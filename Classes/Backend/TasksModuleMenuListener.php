<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Backend;

use Featvalue\Typo3\Api\SiteApi;
use TYPO3\CMS\Backend\Module\BeforeModuleCreationEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Tasks stays hidden in the Web menu until a site type has been chosen.
 */
#[AsEventListener(identifier: 'featvalue/tasks-module-menu')]
final class TasksModuleMenuListener
{
    public function __invoke(BeforeModuleCreationEvent $event): void
    {
        if ($event->getIdentifier() !== 'web_featvalue_tasks') {
            return;
        }
        if ($this->siteTypeIsSet()) {
            return;
        }
        $appearance = $event->getConfigurationValue('appearance', []);
        if (!is_array($appearance)) {
            $appearance = [];
        }
        $appearance['renderInModuleMenu'] = false;
        $event->setConfigurationValue('appearance', $appearance);
    }

    private function siteTypeIsSet(): bool
    {
        try {
            return SiteApi::getSiteType() !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
