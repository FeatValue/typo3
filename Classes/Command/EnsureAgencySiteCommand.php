<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'featvalue:ensure-agency-site',
    description: 'Builds the Nordlicht Studio demo site on Bootstrap Package and moves the dashboard to the client portal.'
)]
final class EnsureAgencySiteCommand extends Command
{
    private const TEMPLATE = <<<'TS'
page.includeCSS.agency = EXT:featvalue/Resources/Public/Css/agency-demo.css
TS;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!ExtensionManagementUtility::isLoaded('bootstrap_package')) {
            $output->writeln('<error>bootstrap_package is not installed.</error>');

            return Command::FAILURE;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $root = $connection->executeQuery(
            'SELECT uid FROM pages WHERE pid = 0 AND deleted = 0 ORDER BY uid ASC'
        )->fetchAssociative();
        if ($root === false) {
            $output->writeln('<error>No root page found.</error>');

            return Command::FAILURE;
        }
        $rootId = (int) $root['uid'];
        $connection->update(
            'pages',
            [
                'title' => 'Nordlicht Studio',
                'nav_hide' => 1,
                'slug' => '/',
                'hidden' => 0,
                'doktype' => 1,
            ],
            ['uid' => $rootId]
        );

        $pages = [
            'home' => ['title' => 'Home', 'slug' => '/home', 'doktype' => 4, 'shortcut' => $rootId, 'sorting' => 16],
            'work' => ['title' => 'Work', 'slug' => '/work', 'doktype' => 1, 'shortcut' => 0, 'sorting' => 32],
            'portal' => ['title' => 'Client portal', 'slug' => '/client-portal', 'doktype' => 1, 'shortcut' => 0, 'sorting' => 48],
            'studio' => ['title' => 'Studio', 'slug' => '/studio', 'doktype' => 1, 'shortcut' => 0, 'sorting' => 64],
            'contact' => ['title' => 'Contact', 'slug' => '/contact', 'doktype' => 1, 'shortcut' => 0, 'sorting' => 80],
        ];
        $ids = [];
        $data = [];
        foreach ($pages as $key => $page) {
            $uid = $this->uidBySlug($connection, $page['slug']);
            $record = [
                'pid' => $rootId,
                'title' => $page['title'],
                'slug' => $page['slug'],
                'doktype' => $page['doktype'],
                'shortcut' => $page['shortcut'],
                'shortcut_mode' => 0,
                'hidden' => 0,
                'nav_hide' => 0,
                'sorting' => $page['sorting'],
            ];
            if ($uid > 0) {
                $ids[$key] = $uid;
                $data['pages'][(string) $uid] = $record;
            } else {
                $data['pages']['NEW_' . $key] = $record;
            }
        }

        $this->actAsAdmin();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            $output->writeln('<error>' . implode('; ', $dataHandler->errorLog) . '</error>');

            return Command::FAILURE;
        }

        foreach (['home', 'work', 'portal', 'studio', 'contact'] as $key) {
            if (!isset($ids[$key]) && isset($dataHandler->substNEWwithIDs['NEW_' . $key])) {
                $ids[$key] = (int) $dataHandler->substNEWwithIDs['NEW_' . $key];
            }
        }
        if (!isset($ids['portal'], $ids['work'], $ids['studio'], $ids['contact'])) {
            $output->writeln('<error>Agency pages were not created.</error>');

            return Command::FAILURE;
        }
        $portalId = $ids['portal'];
        $content = [
            'agency-home' => [$rootId, $this->homeHtml(), 16],
            'agency-home-band' => [$rootId, $this->homeBandHtml(), 32],
            'agency-work' => [$ids['work'], '<p>Selected work for Fruit Cakes Corp, Berger Fashion House and TechStart — brand, web and campaigns from one studio.</p>', 16],
            'agency-portal' => [$portalId, $this->portalHtml(), 16],
            'agency-studio' => [$ids['studio'], '<p>Nordlicht Studio · Hamburg. Strategy, design and delivery in one studio.</p>', 16],
            'agency-contact' => [$ids['contact'], '<p>hello@nordlicht.studio · Hamburg</p>', 16],
        ];
        $contentData = [];
        foreach ($content as $marker => [$pid, $html, $sorting]) {
            $uid = $connection->executeQuery(
                'SELECT uid FROM tt_content WHERE header = ? AND deleted = 0',
                [$marker]
            )->fetchOne();
            $record = [
                'pid' => $pid,
                'CType' => 'text',
                'header' => $marker,
                'header_layout' => '100',
                'bodytext' => $html,
                'colPos' => 0,
                'hidden' => 0,
                'sorting' => $sorting,
            ];
            if ($uid === false) {
                $contentData['tt_content']['NEW_' . $marker] = $record;
            } else {
                $contentData['tt_content'][(string) (int) $uid] = $record;
            }
        }
        $contentHandler = GeneralUtility::makeInstance(DataHandler::class);
        $contentHandler->start($contentData, []);
        $contentHandler->process_datamap();
        if ($contentHandler->errorLog !== []) {
            $output->writeln('<error>' . implode('; ', $contentHandler->errorLog) . '</error>');

            return Command::FAILURE;
        }
        $connection->executeStatement(
            "UPDATE tt_content SET pid = ?, sorting = 64, header_layout = 100 WHERE CType = 'featvalue_dashboard' AND deleted = 0",
            [$portalId]
        );
        $this->applyMenuOrder($connection);

        $templates = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_template');
        $templates->executeStatement(
            'UPDATE sys_template SET config = ?, clear = 0 WHERE root = 1 AND deleted = 0',
            [self::TEMPLATE]
        );

        $this->writeSiteConfig($output);
        GeneralUtility::makeInstance(CacheManager::class)->flushCaches();
        $output->writeln('Nordlicht Studio agency site is ready. Dashboard is on /client-portal.');

        return Command::SUCCESS;
    }

    private function applyMenuOrder(Connection $connection): void
    {
        $order = [
            '/home' => 16,
            '/work' => 32,
            '/client-portal' => 48,
            '/studio' => 64,
            '/contact' => 80,
        ];
        foreach ($order as $slug => $sorting) {
            $connection->executeStatement(
                'UPDATE pages SET sorting = ? WHERE slug = ? AND deleted = 0',
                [$sorting, $slug]
            );
        }
        $connection->executeStatement(
            "UPDATE tt_content SET sorting = 16 WHERE header = 'agency-portal' AND deleted = 0"
        );
        $connection->executeStatement(
            "UPDATE tt_content SET sorting = 16 WHERE header = 'agency-home' AND deleted = 0"
        );
        $connection->executeStatement(
            "UPDATE tt_content SET sorting = 32 WHERE header = 'agency-home-band' AND deleted = 0"
        );
    }

    private function uidBySlug(Connection $connection, string $slug): int
    {
        $uid = $connection->executeQuery(
            'SELECT uid FROM pages WHERE slug = ? AND deleted = 0',
            [$slug]
        )->fetchOne();

        return $uid === false ? 0 : (int) $uid;
    }

    private function writeSiteConfig(OutputInterface $output): void
    {
        $configDir = Environment::getConfigPath() . '/sites';
        foreach (glob($configDir . '/*/config.yaml') ?: [] as $file) {
            $yaml = (string) file_get_contents($file);
            if (!str_contains($yaml, 'websiteTitle:')) {
                $yaml = rtrim($yaml) . "\nwebsiteTitle: 'Nordlicht Studio'\n";
            }
            $yaml = (string) preg_replace(
                '/dependencies:\n(?:  - .+\n)*/',
                "dependencies:\n  - bootstrap-package/full\n  - featvalue/dashboard\n",
                $yaml
            );
            file_put_contents($file, $yaml);
            $settings = dirname($file) . '/settings.yaml';
            file_put_contents($settings, <<<'YAML'
page.theme.cookieconsent.enable: false
page.theme.googleFont.enable: false
page.theme.navigation.style: inverse
page.logo.file: ''
page.logo.fileInverted: ''
YAML);
            $output->writeln('Site set updated in ' . $file);
        }
    }

    private function homeHtml(): string
    {
        return <<<'HTML'
<p class="nl-kicker">Independent studio — brand, web, campaigns</p>
<h1 class="nl-display">We rebuild brands people remember.</h1>
<p class="nl-lead">Nordlicht Studio partners with ambitious companies from strategy to launch — then keeps the work moving in a client portal on this site.</p>
<p class="nl-actions"><a class="nl-btn nl-btn-light" href="/client-portal">Open client portal</a> <a class="nl-btn nl-btn-ghost" href="/work">See selected work</a></p>
HTML;
    }

    private function homeBandHtml(): string
    {
        return <<<'HTML'
<p class="nl-kicker">What we do</p>
<h2 class="nl-display">Strategy, design, and delivery in one studio.</h2>
HTML;
    }

    private function portalHtml(): string
    {
        return <<<'HTML'
<p class="nl-kicker">Client portal</p>
<h2 class="nl-display">Your work, in one place</h2>
<p class="nl-lead">Follow project status, leave feedback, and create new tasks — on this website, in our design. No second set of tools.</p>
HTML;
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
