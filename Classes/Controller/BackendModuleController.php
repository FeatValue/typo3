<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Controller;

use Featvalue\Typo3\Api\SignedClient;
use Featvalue\Typo3\Api\SiteApi;
use Featvalue\Typo3\Connect\ConnectService;
use Featvalue\Typo3\Crypto\KeyStore;
use Featvalue\Typo3\Embed\DashboardRenderer;
use Featvalue\Typo3\Options;
use Featvalue\Typo3\User\CmsUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

final class BackendModuleController
{
    public function __construct(private readonly ModuleTemplateFactory $moduleTemplateFactory) {}

    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        KeyStore::ensureKeypair();
        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $action = (string) ($body['action'] ?? $query['action'] ?? 'settings');
        $error = '';

        try {
            if ($action === 'callback' || isset($query['connection_token'])) {
                ConnectService::handleCallback($query);

                return new RedirectResponse((string) $this->moduleUri());
            }
            if ($action === 'connect') {
                $this->assertToken($request, (string) ($query['formToken'] ?? $body['formToken'] ?? ''));
                $user = CmsUser::fromBackend();

                return $this->topRedirect($request, ConnectService::startConnect($user['email'] ?? ''));
            }
            if ($request->getMethod() === 'POST') {
                $this->assertToken($request, (string) ($body['formToken'] ?? ''));
                $this->handlePost($action, $body);
                if (in_array($action, ['saveSiteType', 'disconnect'], true)) {
                    return $this->topRedirect($request, (string) $this->moduleUri());
                }

                return new RedirectResponse((string) $this->moduleUri());
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        SiteApi::syncSetup();
        $connected = SiteApi::isConnected();
        $siteType = SiteApi::getSiteType();
        $clientId = SiteApi::getClientId();
        $module = $this->moduleTemplateFactory->create($request);
        $module->setTitle('FeatValue');
        $module->assignMultiple([
            'error' => $error,
            'mailError' => (string) Options::get(Options::MAIL_ERROR),
            'connected' => $connected,
            'siteType' => $siteType,
            'clientId' => $clientId,
            'clients' => ($connected && $siteType === 'client' && $clientId < 1) ? SiteApi::fetchClients() : [],
            'ssoEnabled' => Options::isSsoEnabled(),
            'ssoAvailable' => Options::isSsoAvailable(),
            'token' => $this->formProtection()->generateToken('featvalue', 'module'),
            'connectUrl' => (string) $this->moduleUri([
                'action' => 'connect',
                'formToken' => $this->formProtection()->generateToken('featvalue', 'module'),
            ]),
            'moduleUrl' => (string) $this->moduleUri(),
        ]);
        if ($connected && $siteType !== '') {
            $this->addDisconnectButton($module);
        }

        return $module->renderResponse('Backend/Settings');
    }

    public function tasksAction(ServerRequestInterface $request): ResponseInterface
    {
        if (SiteApi::getSiteType() === '') {
            return new RedirectResponse((string) $this->moduleUri());
        }
        $module = $this->moduleTemplateFactory->create($request);
        $module->setTitle('Tasks');
        $module->assign('featvalueHtml', DashboardRenderer::render('calc(100vh - 220px)', 'backend', $request));

        return $module->renderResponse('Backend/Tasks');
    }

    private function addDisconnectButton(\TYPO3\CMS\Backend\Template\ModuleTemplate $module): void
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'Disconnect';
        if (is_object($languageService) && method_exists($languageService, 'sL')) {
            $translated = $languageService->sL('LLL:EXT:featvalue/Resources/Private/Language/locallang.xlf:settings.disconnect');
            if (is_string($translated) && $translated !== '') {
                $label = $translated;
            }
        }
        $buttonBar = $module->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeInputButton()
                ->setName('disconnect')
                ->setValue('1')
                ->setForm('featvalue-disconnect-form')
                ->setTitle($label)
                ->setShowLabelText(true)
                ->setIcon(GeneralUtility::makeInstance(IconFactory::class)->getIcon('actions-close', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_RIGHT,
            1
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handlePost(string $action, array $body): void
    {
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($action === 'disconnect') {
            SiteApi::disconnect();

            return;
        }
        if ($action === 'saveSiteType') {
            $type = (string) ($body['site_type'] ?? '');
            if (!in_array($type, ['client', 'agency'], true) || SiteApi::getSiteType() !== '') {
                return;
            }
            $result = SignedClient::request('POST', '/api/self-hosted/' . $siteId . '/site-type', ['site_type' => $type]);
            if (($result['status'] ?? 0) === 200) {
                Options::set(Options::SITE_TYPE, $type);
            }

            return;
        }
        if ($action === 'saveClient') {
            $clientId = (int) ($body['featvalue_client_id'] ?? 0);
            $result = SignedClient::request('POST', '/api/self-hosted/' . $siteId . '/client', [
                'featvalue_client_id' => $clientId,
            ]);
            if (($result['status'] ?? 0) === 200) {
                Options::set(Options::CLIENT_ID, $clientId);
            }

            return;
        }
        if ($action === 'saveSso') {
            Options::set(Options::SSO_ENABLED, !empty($body['sso_enabled']) ? '1' : '0');
        }
    }

    private function topRedirect(ServerRequestInterface $request, string $url): HtmlResponse
    {
        $nonce = '';
        $nonceAttribute = $request->getAttribute('nonce');
        if ($nonceAttribute instanceof ConsumableNonce) {
            $nonce = $nonceAttribute->consumeStatic();
        }
        $script = PathUtility::getPublicResourceWebPath('EXT:featvalue/Resources/Public/JavaScript/top-redirect.js');
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FeatValue</title></head><body>'
            . '<p>Weiterleitung zu FeatValue …</p>'
            . '<div id="fv-top-redirect" data-url="' . htmlspecialchars($url, ENT_QUOTES) . '"></div>'
            . '<script src="' . htmlspecialchars($script, ENT_QUOTES) . '"'
            . ($nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '')
            . '></script></body></html>';

        return new HtmlResponse($html);
    }

    private function assertToken(ServerRequestInterface $request, string $token): void
    {
        if (!$this->formProtection()->validateToken($token, 'featvalue', 'module')) {
            throw new \RuntimeException('Invalid token');
        }
    }

    private function formProtection(): \TYPO3\CMS\Core\FormProtection\AbstractFormProtection
    {
        return GeneralUtility::makeInstance(FormProtectionFactory::class)->createForType('backend');
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function moduleUri(array $parameters = []): \Psr\Http\Message\UriInterface
    {
        return GeneralUtility::makeInstance(UriBuilder::class)->buildUriFromRoute('featvalue', $parameters);
    }
}
