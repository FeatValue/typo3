<?php

declare(strict_types=1);

namespace Featvalue\Typo3\User;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * TYPO3 unterscheidet Backend- und Frontend-User. Die IDs kollidieren,
 * deshalb trägt cms_user_id das Präfix be: bzw. fe:.
 */
final class CmsUser
{
    /**
     * @return array{cms_user_id: string, email: string, name: string}|null
     */
    public static function fromBackend(): ?array
    {
        $user = $GLOBALS['BE_USER']->user ?? null;
        if (!is_array($user) || empty($user['uid']) || empty($user['email'])) {
            return null;
        }

        return [
            'cms_user_id' => 'be:' . (int) $user['uid'],
            'email' => (string) $user['email'],
            'name' => (string) ($user['realName'] ?: $user['username'] ?? ''),
        ];
    }

    /**
     * @return array{cms_user_id: string, email: string, name: string}|null
     */
    public static function fromFrontend(ServerRequestInterface $request): ?array
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            return null;
        }
        $user = $frontendUser->user;
        if (!is_array($user) || empty($user['uid']) || empty($user['email'])) {
            try {
                $aspect = GeneralUtility::makeInstance(Context::class)->getAspect('frontend.user');
                if (!$aspect->isLoggedIn()) {
                    return null;
                }
            } catch (\Throwable $e) {
                return null;
            }

            return null;
        }

        return [
            'cms_user_id' => 'fe:' . (int) $user['uid'],
            'email' => (string) $user['email'],
            'name' => (string) (($user['name'] ?? '') !== '' ? $user['name'] : ($user['username'] ?? '')),
        ];
    }
}
