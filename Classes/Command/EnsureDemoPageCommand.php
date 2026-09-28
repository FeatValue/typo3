<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Command;

use Featvalue\Typo3\Task\MailRelayTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'featvalue:ensure-demo-page', description: 'Creates a page with the FeatValue dashboard content element and enables the site set.')]
final class EnsureDemoPageCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $root = $connection->executeQuery(
            'SELECT uid FROM pages WHERE pid = 0 AND deleted = 0 ORDER BY uid ASC'
        )->fetchAssociative();
        if ($root === false) {
            $output->writeln('<error>No root page found.</error>');

            return Command::FAILURE;
        }
        $rootId = (int) $root['uid'];
        $existing = $connection->executeQuery(
            "SELECT uid FROM tt_content WHERE CType = 'featvalue_dashboard' AND deleted = 0"
        )->fetchOne();
        if ($existing === false) {
            $this->actAsAdmin();
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([
                'tt_content' => [
                    'NEW1' => [
                        'pid' => $rootId,
                        'CType' => 'featvalue_dashboard',
                        'header' => 'FeatValue',
                        'colPos' => 0,
                    ],
                ],
            ], []);
            $dataHandler->process_datamap();
            if ($dataHandler->errorLog !== []) {
                $output->writeln('<error>' . implode('; ', $dataHandler->errorLog) . '</error>');

                return Command::FAILURE;
            }
            $output->writeln('FeatValue content element created on page ' . $rootId . '.');
        } else {
            $output->writeln('FeatValue content element already exists.');
        }

        $configDir = Environment::getConfigPath() . '/sites';
        if (is_dir($configDir)) {
            foreach (glob($configDir . '/*/config.yaml') ?: [] as $file) {
                $yaml = (string) file_get_contents($file);
                if (!str_contains($yaml, 'featvalue/dashboard')) {
                    if (preg_match('/^dependencies:\s*\n/m', $yaml)) {
                        $yaml = (string) preg_replace('/^dependencies:\s*\n/m', "dependencies:\n  - featvalue/dashboard\n", $yaml, 1);
                    } else {
                        $yaml = rtrim($yaml) . "\ndependencies:\n  - typo3/fluid-styled-content\n  - featvalue/dashboard\n";
                    }
                    file_put_contents($file, $yaml);
                    $output->writeln('Site set added to ' . $file);
                }
            }
        }

        $this->keepSiteSetTypoScript($output);
        $this->ensureMailRelayTask($output);

        return Command::SUCCESS;
    }

    /**
     * TYPO3 setup creates a root template with "clear" set. That drops TypoScript
     * from site sets, so the dashboard content element would have no rendering.
     */
    private function keepSiteSetTypoScript(OutputInterface $output): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_template');
        $updated = $connection->update(
            'sys_template',
            ['clear' => 0],
            ['root' => 1, 'deleted' => 0, 'clear' => 3]
        );
        if ($updated > 0) {
            $output->writeln('Root template no longer clears site set TypoScript.');
        }
    }

    private function ensureMailRelayTask(OutputInterface $output): void
    {
        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_scheduler_task');
            $rows = $connection->executeQuery(
                'SELECT serialized_task_object FROM tx_scheduler_task WHERE deleted = 0'
            )->fetchAllAssociative();
            foreach ($rows as $row) {
                if (str_contains((string) $row['serialized_task_object'], MailRelayTask::class)) {
                    $output->writeln('Mail relay scheduler task already exists.');

                    return;
                }
            }
            $task = GeneralUtility::makeInstance(MailRelayTask::class);
            $task->setDescription('FeatValue mail relay');
            $task->registerRecurringExecution($GLOBALS['EXEC_TIME'] ?? time(), 300);
            GeneralUtility::makeInstance(SchedulerTaskRepository::class)->add($task);
            $output->writeln('Mail relay scheduler task registered (every 5 minutes).');
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not register mail relay task: ' . $e->getMessage() . '</error>');
        }
    }

    private function actAsAdmin(): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $GLOBALS['BE_USER'] = $backendUser;
        }
        if (!is_array($backendUser->user)) {
            $backendUser->user = ['uid' => 0, 'username' => '_cli_'];
        }
        $backendUser->user['admin'] = 1;
        $backendUser->setWorkspace(0);
    }
}
