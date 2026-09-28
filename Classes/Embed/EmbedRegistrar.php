<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Embed;

use Featvalue\Typo3\Api\SignedClient;
use Featvalue\Typo3\Api\SiteApi;
use Featvalue\Typo3\Options;
use Psr\Http\Message\ServerRequestInterface;

final class EmbedRegistrar
{
    public static function register(string $context, ServerRequestInterface $request): void
    {
        if (!SiteApi::isConnected() || !SiteApi::isSetupComplete()) {
            return;
        }
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId < 1) {
            return;
        }
        $uri = $request->getUri();
        $pageUrl = (string) $uri;
        $title = 'FeatValue';
        $hash = md5($pageUrl);
        $registered = json_decode((string) Options::get(Options::REGISTERED_EMBED_URLS, '{}'), true);
        if (!is_array($registered)) {
            $registered = [];
        }
        if ($context === 'frontend' && isset($registered[$hash])) {
            return;
        }
        try {
            $result = SignedClient::request('POST', '/api/self-hosted/' . $siteId . '/embed-page', [
                'page_url' => $pageUrl,
                'title' => $title,
                'context' => $context,
            ]);
            if (in_array((int) ($result['status'] ?? 0), [200, 201], true)) {
                $registered[$hash] = time();
                Options::set(Options::REGISTERED_EMBED_URLS, json_encode($registered));
            }
        } catch (\Throwable $e) {
        }
    }
}
