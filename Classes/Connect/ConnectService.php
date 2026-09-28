<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Connect;

use Featvalue\Typo3\Api\SignedClient;
use Featvalue\Typo3\Api\SiteApi;
use Featvalue\Typo3\Config;
use Featvalue\Typo3\Crypto\KeyStore;
use Featvalue\Typo3\Options;

final class ConnectService
{
    public static function startConnect(string $email = ''): string
    {
        KeyStore::ensureKeypair();
        $nonce = random_bytes(32);
        Options::set(Options::PAIRING_NONCE, base64_encode($nonce));
        $state = bin2hex(random_bytes(16));
        Options::set('pairing_state', $state);
        $params = [
            'site_url' => Config::siteUrl(),
            'site_name' => Config::siteName(),
            'public_key' => (string) Options::get(Options::PUBLIC_KEY),
            'site_uuid' => (string) Options::get(Options::SITE_UUID),
            'pairing_nonce_hash' => hash('sha256', $nonce),
            'return_url' => rtrim(Config::siteUrl(), '/') . '/typo3/module/featvalue?action=callback',
            'state' => $state,
            'locale' => Config::featvalueLang() !== '' ? Config::featvalueLang() : 'en',
        ];
        if ($email !== '') {
            $params['suggested_email'] = $email;
        }
        $result = SignedClient::postForm(
            Config::integrationsUrlForServer() . '/api/self-hosted/begin-pairing/typo3',
            $params
        );
        $body = $result['body'];
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($body) || empty($body['redirect'])) {
            $message = is_array($body) && !empty($body['error']) ? (string) $body['error'] : 'Could not start FeatValue connection.';
            throw new \RuntimeException($message);
        }

        return (string) $body['redirect'];
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function handleCallback(array $query): void
    {
        $token = (string) ($query['connection_token'] ?? '');
        $sig = (string) ($query['sig'] ?? '');
        $state = (string) ($query['state'] ?? '');
        $siteId = (int) ($query['site_id'] ?? 0);
        if ($token === '' || $sig === '') {
            throw new \RuntimeException('Token or signature missing.');
        }
        if (!hash_equals((string) Options::get('pairing_state'), $state)) {
            throw new \RuntimeException('Invalid CSRF state.');
        }
        $nonce = base64_decode((string) Options::get(Options::PAIRING_NONCE), true);
        if ($nonce === false) {
            throw new \RuntimeException('Pairing nonce not found.');
        }
        $expected = hash_hmac('sha256', $token, hash('sha256', $nonce));
        if (!hash_equals($expected, $sig)) {
            throw new \RuntimeException('Invalid connection token signature.');
        }
        Options::delete(Options::PAIRING_NONCE);
        Options::delete('pairing_state');
        Options::set(Options::CONNECTION_TOKEN, $token);
        if ($siteId > 0) {
            Options::set(Options::SITE_ID, $siteId);
        }
        SiteApi::syncSetup();
    }
}
