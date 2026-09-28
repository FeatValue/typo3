<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Task;

use Featvalue\Typo3\Mail\MailRelay;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

final class MailRelayTask extends AbstractTask
{
    public function execute(): bool
    {
        MailRelay::pull();

        return true;
    }
}
