<?php

declare(strict_types=1);

namespace Featvalue\Typo3;

use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Options
{
    public const SITE_UUID = 'site_uuid';
    public const PUBLIC_KEY = 'public_key';
    public const SECRET_KEY = 'secret_key_enc';
    public const CONNECTION_TOKEN = 'connection_token';
    public const PAIRING_NONCE = 'pairing_nonce';
    public const SITE_ID = 'site_id';
    public const SITE_TYPE = 'site_type';
    public const CLIENT_ID = 'client_id';
    public const DASHBOARD_ID = 'dashboard_id';
    public const REGISTERED_EMBED_URLS = 'registered_embed_urls';
    public const MAIL_ERROR = 'mail_error';
    public const SSO_ENABLED = 'sso_enabled';
    public const SSO_AVAILABLE = 'sso_available';
    public const BOOTSTRAP_LAST_GOOD = 'bootstrap_last_good';

    private const NAMESPACE = 'featvalue';

    public static function get(string $key, mixed $default = ''): mixed
    {
        return self::registry()->get(self::NAMESPACE, $key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        self::registry()->set(self::NAMESPACE, $key, $value);
    }

    public static function delete(string $key): void
    {
        self::registry()->remove(self::NAMESPACE, $key);
    }

    public static function isSsoEnabled(): bool
    {
        return (string) self::get(self::SSO_ENABLED, '0') === '1';
    }

    public static function isSsoAvailable(): bool
    {
        return (string) self::get(self::SSO_AVAILABLE, '0') === '1';
    }

    private static function registry(): Registry
    {
        return GeneralUtility::makeInstance(Registry::class);
    }
}
