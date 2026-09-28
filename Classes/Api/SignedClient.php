<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Api;

use Featvalue\Typo3\Config;
use Featvalue\Typo3\Crypto\KeyStore;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SignedClient
{
    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: mixed}
     */
    public static function request(string $method, string $path, array $body = []): array
    {
        $secret = KeyStore::decryptSecretKey();
        if ($secret === false) {
            throw new \RuntimeException('No FeatValue private key found.');
        }
        $method = strtoupper($method);
        $json = $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signPath = strtok($path, '?') ?: $path;
        $ts = (string) microtime(true);
        $payload = $ts . '|' . $method . '|' . $signPath . '|' . hash('sha256', $json);
        $sig = sodium_crypto_sign_detached($payload, $secret);
        sodium_memzero($secret);
        $headers = array_merge(Config::upstreamHeaders(), [
            'X-Fv-Timestamp' => $ts,
            'X-Fv-Signature' => base64_encode($sig),
            'Accept' => 'application/json',
        ]);
        $options = [
            'headers' => $headers,
            'timeout' => 15,
            'http_errors' => false,
        ];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
            $options['headers'] = $headers;
            $options['body'] = $json;
        }
        $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
            rtrim(Config::integrationsUrlForServer(), '/') . $path,
            $method,
            $options
        );
        $raw = (string) $response->getBody();
        $parsed = json_decode($raw, true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($parsed) ? $parsed : $raw,
        ];
    }

    /**
     * @param array<string, scalar> $params
     * @return array{status: int, body: mixed}
     */
    public static function postForm(string $url, array $params): array
    {
        $response = GeneralUtility::makeInstance(RequestFactory::class)->request($url, 'POST', [
            'headers' => array_merge(Config::upstreamHeaders(), [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]),
            'body' => http_build_query($params),
            'timeout' => 45,
            'http_errors' => false,
        ]);
        $raw = (string) $response->getBody();
        $parsed = json_decode($raw, true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($parsed) ? $parsed : $raw,
        ];
    }
}
