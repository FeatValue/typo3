<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Embed;

use Featvalue\Typo3\Api\Bootstrap;
use Featvalue\Typo3\Api\SignedClient;
use Featvalue\Typo3\Api\SiteApi;
use Featvalue\Typo3\Config;
use Featvalue\Typo3\Options;
use Featvalue\Typo3\User\CmsUser;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class DashboardRenderer
{
    public static function render(string $height, string $context, ?ServerRequestInterface $request = null): string
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return self::notice(Bootstrap::i18n('dashboard_load_failed', 'The dashboard could not be loaded.'));
        }
        SiteApi::syncSetup();
        if (!SiteApi::isConnected()) {
            return self::notice(Bootstrap::i18n('not_connected', self::label('content.notConnected')));
        }
        if (!SiteApi::isSetupComplete()) {
            return self::notice(Bootstrap::i18n('finish_setup', self::label('content.finishSetup')));
        }
        $user = $context === 'backend' ? CmsUser::fromBackend() : CmsUser::fromFrontend($request);
        if ($context === 'frontend' && Options::isSsoEnabled() && $user === null) {
            return self::notice(self::label('content.ssoLoginRequired'));
        }
        $embed = self::fetchDashboardEmbed($user, false);
        if (!empty($embed['email_verification_required'])) {
            return self::verificationNotice();
        }

        return self::renderIframe($embed, $height, $context, $request, $user === null);
    }

    /**
     * @param array{cms_user_id: string, email: string, name: string}|null $user
     * @return array{embed_url?: string, dashboard_id?: int, error?: string, email_verification_required?: bool, sso?: bool}
     */
    public static function fetchDashboardEmbed(?array $user, bool $forceEmailVerification): array
    {
        if (Options::isSsoEnabled() && $user !== null && !$forceEmailVerification) {
            $sso = self::fetchSsoEmbed($user);
            if ($sso['available']) {
                return [
                    'embed_url' => $sso['embed_url'],
                    'dashboard_id' => $sso['dashboard_id'],
                    'error' => '',
                    'sso' => true,
                ];
            }
        }

        return self::fetchTasksEmbed($user, $forceEmailVerification);
    }

    private static function verificationNotice(): string
    {
        $message = htmlspecialchars(Bootstrap::i18n('email_verification_required', self::label('content.emailVerification')), ENT_QUOTES, 'UTF-8');
        $button = htmlspecialchars(self::label('content.resend'), ENT_QUOTES, 'UTF-8');

        return '<p class="featvalue-dashboard-notice">' . $message . '</p>'
            . '<form method="post" action="/featvalue/v1/resend-email-verification">'
            . '<button type="submit">' . $button . '</button></form>';
    }

    /**
     * @param array<string, mixed> $embed
     */
    private static function renderIframe(array $embed, string $height, string $context, ServerRequestInterface $request, bool $anonymous): string
    {
        $height = Config::sanitizeIframeHeight($height);
        $url = (string) ($embed['embed_url'] ?? '');
        if ($url === '' || !Config::isAllowedEmbedUrl($url)) {
            $error = (string) ($embed['error'] ?? Bootstrap::i18n('dashboard_load_failed', 'The dashboard could not be loaded.'));

            return self::notice($error);
        }
        $lang = Config::featvalueLang();
        if ($lang !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang);
        }
        EmbedRegistrar::register($context === 'backend' ? 'admin' : 'frontend', $request);
        $guestEmbed = $anonymous || str_contains($url, 'cms_anonymous=1');
        $script = self::scriptTags(empty($embed['sso']) && !$guestEmbed, $request);
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        $dashboardId = (int) ($embed['dashboard_id'] ?? Options::get(Options::DASHBOARD_ID, 0));
        $dashboardOrigin = htmlspecialchars(Config::localDashboardOrigin(), ENT_QUOTES, 'UTF-8');
        $embedOrigin = htmlspecialchars(Config::originOf($url), ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return $script
            . '<div class="featvalue-dashboard-wrap" style="width:100%;">'
            . '<iframe id="featvalue-dashboard-iframe" title="FeatValue" src="' . $safeUrl . '" data-fv-embed-base="' . $safeUrl . '"'
            . ' data-fv-dashboard-origin="' . $dashboardOrigin . '" data-fv-embed-origin="' . $embedOrigin . '"'
            . ' data-fv-dashboard-id="' . $dashboardId . '" data-fv-site-id="' . $siteId . '"'
            . ' style="width:100%;height:' . htmlspecialchars($height, ENT_QUOTES, 'UTF-8') . ';border:0;display:block;"'
            . ' referrerpolicy="strict-origin-when-cross-origin" allow="clipboard-read; clipboard-write"></iframe></div>';
    }

    private static function scriptTags(bool $topLevelLogin, ServerRequestInterface $request): string
    {
        $remote = Bootstrap::scriptUrl();
        $local = rtrim(Config::siteUrl(), '/') . '/typo3conf/ext/featvalue/Resources/Public/JavaScript/embed-parent.js';
        $publicPath = PathUtility::getPublicResourceWebPath('EXT:featvalue/Resources/Public/JavaScript/embed-parent.js');
        if (is_string($publicPath) && $publicPath !== '') {
            $local = $publicPath;
            if (!str_starts_with($local, 'http')) {
                $origin = Config::originOf(Config::siteUrl() . '/');
                $local = rtrim($origin, '/') . '/' . ltrim($local, '/');
            }
        }
        $src = $remote !== '' ? $remote : $local;
        $config = [
            'authHostSuffixes' => Config::allowedAuthHostSuffixes(),
            'topLevelLogin' => $topLevelLogin ? '1' : '0',
            'i18n' => [
                'loginRequired' => Bootstrap::i18n('sign_in_required', 'Sign in to show this dashboard.'),
                'loginButton' => Bootstrap::i18n('sign_in_button', 'Sign in'),
                'loginHint' => Bootstrap::i18n('sign_in_hint', 'A new window opens for sign-in.'),
                'registerButton' => Bootstrap::i18n('register_button', 'Register'),
            ],
            'registerUrl' => Bootstrap::registerUrl(),
            'lang' => Config::featvalueLang(),
        ];
        $nonceAttr = self::cspNonceAttribute($request);
        $html = '<script' . $nonceAttr . '>window.featvalueEmbedParent = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
        $html .= '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" defer></script>';
        if ($remote !== '') {
            $html .= '<script' . $nonceAttr . '>(function(){var s=document.querySelector('
                . json_encode('script[src="' . $remote . '"]', JSON_UNESCAPED_SLASHES)
                . ');'
                . 'if(!s){return;}s.addEventListener("error",function(){if(window.__fvHostJsFallback){return;}window.__fvHostJsFallback=1;'
                . 'var f=document.createElement("script");f.src=' . json_encode($local, JSON_UNESCAPED_SLASHES) . ';document.head.appendChild(f);});})();</script>';
        }

        return $html;
    }

    private static function cspNonceAttribute(ServerRequestInterface $request): string
    {
        $nonceAttribute = $request->getAttribute('nonce');
        if (!$nonceAttribute instanceof ConsumableNonce) {
            return '';
        }

        return ' nonce="' . htmlspecialchars($nonceAttribute->consumeInline(), ENT_QUOTES) . '"';
    }

    /**
     * @param array{cms_user_id: string, email: string, name: string} $user
     * @return array{embed_url: string, available: bool, dashboard_id: int}
     */
    private static function fetchSsoEmbed(array $user): array
    {
        $empty = ['embed_url' => '', 'available' => false, 'dashboard_id' => 0];
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId < 1 || !SiteApi::isSetupComplete()) {
            return $empty;
        }
        try {
            $result = SignedClient::request('POST', '/api/self-hosted/' . $siteId . '/sso-embed', [
                'email' => $user['email'],
                'name' => $user['name'] !== '' ? $user['name'] : $user['email'],
                'cms_user_id' => $user['cms_user_id'],
            ]);
        } catch (\Throwable $e) {
            return $empty;
        }
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        if (($result['status'] ?? 0) !== 200 || empty($body['embed_url']) || !Config::isAllowedEmbedUrl((string) $body['embed_url'])) {
            return $empty;
        }

        return [
            'embed_url' => (string) $body['embed_url'],
            'available' => true,
            'dashboard_id' => (int) ($body['dashboard_id'] ?? 0),
        ];
    }

    /**
     * @param array{cms_user_id: string, email: string, name: string}|null $user
     * @return array{embed_url: string, dashboard_id: int, error: string, email_verification_required: bool}
     */
    private static function fetchTasksEmbed(?array $user, bool $forceEmailVerification): array
    {
        $empty = ['embed_url' => '', 'dashboard_id' => 0, 'error' => '', 'email_verification_required' => false];
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId < 1) {
            return $empty + ['error' => Bootstrap::i18n('not_connected', self::label('content.notConnected'))];
        }
        $body = [];
        $method = 'GET';
        if ($user !== null) {
            $method = 'POST';
            $body = [
                'email' => $user['email'],
                'name' => $user['name'] !== '' ? $user['name'] : $user['email'],
                'cms_user_id' => $user['cms_user_id'],
            ];
            if ($forceEmailVerification) {
                $body['force_email_verification'] = true;
            }
        }
        try {
            $result = SignedClient::request($method, '/api/self-hosted/' . $siteId . '/tasks-embed', $body);
        } catch (\Throwable $e) {
            return $empty + ['error' => $e->getMessage()];
        }
        $response = is_array($result['body'] ?? null) ? $result['body'] : [];
        if (!empty($response['email_verification_required'])) {
            return $empty + ['email_verification_required' => true];
        }
        if (($result['status'] ?? 0) !== 200 || empty($response['embed_url'])) {
            return $empty + ['error' => (string) ($response['error'] ?? 'embed_unavailable')];
        }
        $embedUrl = (string) $response['embed_url'];
        if ($user === null) {
            $embedUrl .= (str_contains($embedUrl, '?') ? '&' : '?') . 'cms_anonymous=1';
        }

        return [
            'embed_url' => $embedUrl,
            'dashboard_id' => (int) ($response['dashboard_id'] ?? 0),
            'error' => '',
            'email_verification_required' => false,
        ];
    }

    private static function notice(string $message): string
    {
        return '<p class="featvalue-dashboard-notice">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    private static function label(string $key): string
    {
        if (!class_exists(LocalizationUtility::class)) {
            return $key;
        }
        $label = LocalizationUtility::translate($key, 'featvalue');
        if (!is_string($label) || $label === '') {
            return $key;
        }

        return $label;
    }
}
