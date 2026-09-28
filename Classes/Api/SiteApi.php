<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Api;

use Featvalue\Typo3\Options;

final class SiteApi
{
    public static function getSiteType(): string
    {
        $type = (string) Options::get(Options::SITE_TYPE);

        return in_array($type, ['client', 'agency'], true) ? $type : '';
    }

    public static function getClientId(): int
    {
        return (int) Options::get(Options::CLIENT_ID, 0);
    }

    public static function isSetupComplete(): bool
    {
        $type = self::getSiteType();
        if ($type === '') {
            return false;
        }

        return $type !== 'client' || self::getClientId() > 0;
    }

    public static function isConnected(): bool
    {
        return (string) Options::get(Options::CONNECTION_TOKEN) !== '';
    }

    public static function disconnect(): void
    {
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId > 0 && self::isConnected()) {
            try {
                SignedClient::request('POST', '/self-hosted/sites/' . $siteId . '/revoke');
            } catch (\Throwable $e) {
            }
        }
        self::clearConnection();
    }

    public static function clearConnection(): void
    {
        foreach ([
            Options::CONNECTION_TOKEN, Options::SITE_ID, Options::SITE_TYPE, Options::CLIENT_ID,
            Options::DASHBOARD_ID, Options::REGISTERED_EMBED_URLS, Options::PAIRING_NONCE,
            Options::MAIL_ERROR, Options::SSO_AVAILABLE, Options::BOOTSTRAP_LAST_GOOD,
        ] as $key) {
            Options::delete($key);
        }
    }

    public static function syncSetup(): void
    {
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId < 1 || !self::isConnected()) {
            return;
        }
        try {
            $result = SignedClient::request('GET', '/api/self-hosted/' . $siteId . '/site-type');
        } catch (\Throwable $e) {
            return;
        }
        if (($result['status'] ?? 0) === 404) {
            self::clearConnection();

            return;
        }
        if (($result['status'] ?? 0) !== 200 || !is_array($result['body'])) {
            return;
        }
        $body = $result['body'];
        if (self::getSiteType() === '' && !empty($body['site_type'])) {
            $type = $body['site_type'] === 'customer' ? 'client' : $body['site_type'];
            if (in_array($type, ['client', 'agency'], true)) {
                Options::set(Options::SITE_TYPE, $type);
            }
        }
        if (self::getClientId() < 1 && !empty($body['featvalue_client_id'])) {
            Options::set(Options::CLIENT_ID, (int) $body['featvalue_client_id']);
        }
        if (!empty($body['dashboard_id'])) {
            Options::set(Options::DASHBOARD_ID, (int) $body['dashboard_id']);
        }
        if (isset($body['sso_available']) && is_bool($body['sso_available'])) {
            Options::set(Options::SSO_AVAILABLE, $body['sso_available'] ? '1' : '0');
        }
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function fetchClients(): array
    {
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId < 1 || !self::isConnected()) {
            return [];
        }
        try {
            $result = SignedClient::request('GET', '/api/self-hosted/' . $siteId . '/clients');
        } catch (\Throwable $e) {
            return [];
        }
        if (($result['status'] ?? 0) !== 200 || !is_array($result['body']['clients'] ?? null)) {
            return [];
        }
        $clients = [];
        foreach ($result['body']['clients'] as $client) {
            if (!is_array($client) || empty($client['id'])) {
                continue;
            }
            $clients[] = [
                'id' => (int) $client['id'],
                'name' => isset($client['name']) && is_string($client['name']) ? $client['name'] : (string) $client['id'],
            ];
        }

        return $clients;
    }
}
