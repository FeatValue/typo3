<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Middleware;

use Featvalue\Typo3\Api\HealthService;
use Featvalue\Typo3\Crypto\KeyStore;
use Featvalue\Typo3\Embed\DashboardRenderer;
use Featvalue\Typo3\Mail\MailRelay;
use Featvalue\Typo3\User\CmsUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final class ApiMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        $endpoint = null;
        foreach (['health', 'webhook', 'resend-email-verification'] as $name) {
            if (str_ends_with($path, '/featvalue/v1/' . $name)) {
                $endpoint = $name;
                break;
            }
        }
        if ($endpoint === null) {
            return $handler->handle($request);
        }
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'method_not_allowed'], 405);
        }
        try {
            KeyStore::ensureKeypair();
            $raw = (string) $request->getBody();
            $data = json_decode($raw, true);
            if ($endpoint === 'health') {
                $challenge = is_array($data) ? (string) ($data['challenge'] ?? '') : '';
                if ($challenge === '') {
                    $parsed = $request->getParsedBody();
                    $challenge = is_array($parsed) ? (string) ($parsed['challenge'] ?? '') : '';
                }
                if (strlen($challenge) < 16 || strlen($challenge) > 128) {
                    return new JsonResponse(['error' => 'invalid_challenge'], 400);
                }

                return new JsonResponse(['signature' => HealthService::signChallenge($challenge)]);
            }
            if ($endpoint === 'resend-email-verification') {
                $user = CmsUser::fromFrontend($request);
                if ($user === null) {
                    return new JsonResponse(['error' => 'login_required'], 401);
                }
                $embed = DashboardRenderer::fetchDashboardEmbed($user, true);

                return new JsonResponse([
                    'email_verification_required' => !empty($embed['email_verification_required']),
                    'embed_url' => $embed['embed_url'] ?? '',
                ]);
            }
            $ts = $request->getHeaderLine('X-Fv-Timestamp');
            $sig = $request->getHeaderLine('X-Fv-Signature');
            if (!HealthService::verifyWebhook($raw, $ts, $sig)) {
                return new JsonResponse(['error' => 'invalid_signature'], 403);
            }
            $payload = is_array($data) ? $data : [];
            if (($payload['event'] ?? '') === 'mail_pending') {
                MailRelay::pull();
            }

            return new JsonResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
