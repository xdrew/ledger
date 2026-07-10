<?php

declare(strict_types=1);

namespace App\Outbox\Console;

use App\Console\WorkerLoop;
use App\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the outbox relay: one catch-up pass by default, or continuously under
 * `--loop` (the worker deployment), stopping cleanly on SIGTERM.
 */
#[AsCommand(name: 'outbox:relay', description: 'Publish pending domain events from the outbox to the transport.')]
final class RelayCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run continuously until SIGTERM (worker mode).')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between iterations in loop mode.', '1.0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('loop') === true) {
            $interval = $input->getOption('interval');
            new WorkerLoop()->run(
                function (): void {
                    $this->relay->relay();
                },
                is_numeric($interval) ? (float) $interval : 1.0,
            );

            return Command::SUCCESS;
        }

        $published = $this->relay->relay();
        $io->success(\sprintf('Published %d events; relay checkpoint is now %d.', $published, $this->relay->position()));

        return Command::SUCCESS;
    }
}
