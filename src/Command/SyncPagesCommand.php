<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Command;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'emporiqa:sync:pages', description: 'Sync all pages to Emporiqa')]
class SyncPagesCommand extends Command
{
    public function __construct(
        private readonly SyncServiceInterface $syncService,
        private readonly ConfigServiceInterface $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate sync without sending webhooks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->config->isConfigured()) {
            $io->error('Emporiqa plugin is not configured. Please set Store ID and Webhook Secret.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run mode - no webhooks will be sent.');
        }

        $io->title('Syncing pages to Emporiqa');

        $result = $this->syncService->syncPages(
            function (int $count, int $events) use ($io) {
                $io->writeln("  Processed {$count} pages ({$events} events)");
            },
            $dryRun,
        );

        if ($result['success']) {
            $io->success("Sync complete: {$result['pages']} pages, {$result['events']} events sent.\nData will be processed by Emporiqa within the next few minutes.\nCheck your pages at https://emporiqa.com/platform/pages/");
        } else {
            $io->warning("Sync completed with errors: {$result['pages']} pages, {$result['events']} events.");
            foreach ($result['errors'] as $error) {
                $io->error($error);
            }
        }

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }
}
