<?php

declare(strict_types=1);

namespace Featvalue\Typo3;

use Featvalue\Typo3\Api\Bootstrap;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Config
{
    public static function integrationsUrlForBrowser(): string
    {
        $env = getenv('FEATVALUE_INTEGRATIONS_URL');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }

        return self::localConstant('INTEGRATIONS') ?? 'https://integrations.featvalue.io';
    }

    public static function integrationsUrlForServer(): string
    {
        $env = getenv('FEATVALUE_INTEGRATIONS_URL_SERVER');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        $browser = self::integrationsUrlForBrowser();
        if (self::localConstant('INTEGRATIONS') === null) {
            return $browser;
        }
        $host = parse_url($browser, PHP_URL_HOST);
        if (is_string($host) && self::hostResolves($host)) {
            return $browser;
        }
        return $browser;
    }

    /**
     * @return array<string, string>
     */
    public static function upstreamHeaders(): array
    {
        $serverHost = parse_url(self::integrationsUrlForServer(), PHP_URL_HOST);
        $publicHost = parse_url(self::integrationsUrlForBrowser(), PHP_URL_HOST);
        if (!is_string($serverHost) || !is_string($publicHost) || $serverHost === '' || $publicHost === '') {
            return [];
        }
        if (strcasecmp($serverHost, $publicHost) === 0) {
            return [];
        }

        return ['Host' => $publicHost];
    }

    public static function dashboardUrl(): string
    {
        $env = getenv('FEATVALUE_DASHBOARD_URL');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }

        return self::localConstant('DASHBOARD') ?? 'https://dashboard.featvalue.io';
    }

    public static function appUrl(): string
    {
        $env = getenv('FEATVALUE_APP_URL');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }

        return self::localConstant('APP') ?? 'https://app.featvalue.io';
    }

    public static function localDashboardOrigin(): string
    {
        return self::originOf(self::dashboardUrl());
    }

    public static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    public static function siteUrl(): string
    {
        try {
            $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
        } catch (\Throwable $e) {
            $sites = [];
        }
        if ($sites !== []) {
            $site = reset($sites);

            return rtrim((string) $site->getBase(), '/');
        }
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequest) {
            $uri = $request->getUri();

            return $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '');
        }

        return '';
    }

    public static function siteName(): string
    {
        $name = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '');

        return $name !== '' ? $name : 'TYPO3';
    }

    public static function featvalueLang(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $tag = '';
        if ($request instanceof ServerRequest) {
            $language = $request->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                $tag = $language->getLocale()->getLanguageCode();
            }
        }
        if ($tag === '' && isset($GLOBALS['BE_USER']->uc['lang'])) {
            $tag = (string) $GLOBALS['BE_USER']->uc['lang'];
        }
        $primary = strtolower(explode('-', str_replace('_', '-', $tag))[0] ?? '');
        $supported = ['de', 'en', 'es', 'fa', 'fr', 'hi', 'id', 'it', 'nl', 'pl', 'pt', 'ru', 'sv', 'th'];

        return in_array($primary, $supported, true) ? $primary : '';
    }

    public static function isAllowedEmbedUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if ($scheme !== 'https') {
            return false;
        }
        foreach (self::allowedEmbedHosts() as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function allowedEmbedHosts(): array
    {
        $payload = Bootstrap::payload();
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        if (!empty($config['allowed_embed_hosts']) && is_array($config['allowed_embed_hosts'])) {
            return array_map('strval', $config['allowed_embed_hosts']);
        }
        $hosts = ['featvalue.io'];
        foreach ([self::dashboardUrl(), self::integrationsUrlForBrowser()] as $base) {
            $h = parse_url($base, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return list<string>
     */
    public static function allowedAuthHostSuffixes(): array
    {
        $payload = Bootstrap::payload();
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        if (!empty($config['allowed_auth_host_suffixes']) && is_array($config['allowed_auth_host_suffixes'])) {
            return array_map('strval', $config['allowed_auth_host_suffixes']);
        }

        return ['featvalue.io'];
    }

    public static function sanitizeIframeHeight(string $height, string $default = 'min(800px, 80vh)'): string
    {
        $height = trim($height);
        if (preg_match('/^min\(\s*\d+(\.\d+)?px\s*,\s*\d+(\.\d+)?vh\s*\)$/', $height)) {
            return $height;
        }
        if (preg_match('/^\d+(\.\d+)?(px|vh|%|rem|em)$/', $height)) {
            return $height;
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    public static function cspHosts(): array
    {
        $hosts = [
            'https://dashboard.featvalue.io',
            'https://integrations.featvalue.io',
            'https://app.featvalue.io',
            'https://external-auth.featvalue.io',
            'https://login.featvalue.io',
            'https://tasks.featvalue.io',
            'https://register.featvalue.io',
            'https://www.featvalue.io',
            'https://dashboard.featvalue.io',
            'https://dashboard.featvalue.io',
            'https://dashboard.featvalue.io',
            'https://dashboard.featvalue.io',
            'https://dashboard.featvalue.io',
            'https://dashboard.featvalue.io',
            'https://dashboard.featvalue.io',
            'https://dashboard.featvalue.io',
        ];
        foreach ([self::dashboardUrl(), self::integrationsUrlForBrowser(), self::appUrl()] as $base) {
            $origin = self::originOf($base);
            if ($origin !== '') {
                $hosts[] = $origin;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function localConstant(string $name): ?string
    {
        $file = dirname(__DIR__) . '/Classes/Targets.php';
        if (!is_file($file)) {
            return null;
        }
        require_once $file;
        $const = Targets::class . '::' . $name;
        if (!defined($const)) {
            return null;
        }
        $value = constant($const);

        return is_string($value) && $value !== '' ? rtrim($value, '/') : null;
    }

    private static function hostResolves(string $host): bool
    {
        $resolved = gethostbyname($host);

        return $resolved !== $host;
    }
}
