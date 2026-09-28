<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Api;

use Featvalue\Typo3\Config;
use Featvalue\Typo3\Crypto\KeyStore;
use Featvalue\Typo3\Options;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Bootstrap
{
    /** @var array<string, mixed>|null */
    private static ?array $payload = null;

    /**
     * @return array<string, mixed>
     */
    public static function payload(bool $force = false): array
    {
        if (!$force && self::$payload !== null) {
            return self::$payload;
        }
        $fresh = self::fetchRemote();
        if (is_array($fresh)) {
            self::$payload = $fresh;
            Options::set(Options::BOOTSTRAP_LAST_GOOD, json_encode($fresh));

            return $fresh;
        }
        $last = json_decode((string) Options::get(Options::BOOTSTRAP_LAST_GOOD), true);
        self::$payload = is_array($last) ? $last : self::localDefaults();

        return self::$payload;
    }

    public static function i18n(string $key, string $fallback = ''): string
    {
        $payload = self::payload();
        $i18n = is_array($payload['i18n'] ?? null) ? $payload['i18n'] : [];

        return isset($i18n[$key]) && is_string($i18n[$key]) && $i18n[$key] !== '' ? $i18n[$key] : $fallback;
    }

    public static function scriptUrl(): string
    {
        if (getenv('FEATVALUE_FORCE_LOCAL_EMBED_HOST')) {
            return '';
        }
        $payload = self::payload();
        $url = isset($payload['script_url']) && is_string($payload['script_url']) ? $payload['script_url'] : '';

        return (!empty($payload['features']['remote_host_js']) && $url !== '') ? $url : '';
    }

    public static function registerUrl(): string
    {
        $payload = self::payload();

        return isset($payload['register_url']) && is_string($payload['register_url']) ? $payload['register_url'] : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchRemote(): ?array
    {
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId < 1 || (string) Options::get(Options::CONNECTION_TOKEN) === '') {
            return null;
        }
        $signPath = '/api/self-hosted/' . $siteId . '/bootstrap';
        $locale = Config::featvalueLang() !== '' ? Config::featvalueLang() : 'en';
        $requestPath = $signPath . '?locale=' . rawurlencode($locale);
        try {
            $secret = KeyStore::decryptSecretKey();
            if ($secret === false) {
                return null;
            }
            $ts = (string) microtime(true);
            $payload = $ts . '|GET|' . $signPath . '|' . hash('sha256', '');
            $sig = sodium_crypto_sign_detached($payload, $secret);
            sodium_memzero($secret);
            $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
                rtrim(Config::integrationsUrlForServer(), '/') . $requestPath,
                'GET',
                [
                    'headers' => array_merge(Config::upstreamHeaders(), [
                        'X-Fv-Timestamp' => $ts,
                        'X-Fv-Signature' => base64_encode($sig),
                        'Accept' => 'application/json',
                    ]),
                    'timeout' => 15,
                    'http_errors' => false,
                ]
            );
            $body = json_decode((string) $response->getBody(), true);

            return ($response->getStatusCode() === 200 && is_array($body)) ? $body : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function localDefaults(): array
    {
        $hosts = ['featvalue.io'];
        foreach ([Config::dashboardUrl(), Config::integrationsUrlForBrowser()] as $base) {
            $h = parse_url($base, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }

        return [
            'config' => [
                'allowed_embed_hosts' => array_values(array_unique($hosts)),
                'allowed_auth_host_suffixes' => ['featvalue.io'],
                'dashboard_origin' => Config::localDashboardOrigin(),
                'supported_locales' => ['de', 'en', 'es', 'fa', 'fr', 'hi', 'id', 'it', 'nl', 'pl', 'pt', 'ru', 'sv', 'th'],
            ],
            'i18n' => [],
            'features' => ['remote_host_js' => false, 'state_driven_gates' => false],
            'script_url' => '',
        ];
    }
}
