<?php

declare(strict_types=1);

namespace App\Observability\Metrics\Console;

use App\Console\WorkerLoop;
use App\Observability\Metrics\GaugeCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Refreshes the DB-derived gauges: once by default, or continuously under
 * `--loop` (the worker runtime) so `outbox_pending` / `projection_lag_seconds` /
 * `holds_active` stay current for Prometheus scrapes. The PHP-side loop exists
 * because RoadRunner service commands are not shell-parsed — a `sh -c 'while …'`
 * one-liner gets mangled.
 */
#[AsCommand(name: 'metrics:collect', description: 'Refresh the DB-derived observability gauges.')]
final class CollectMetricsCommand extends Command
{
    public function __construct(private readonly GaugeCollector $collector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run continuously until SIGTERM (worker mode).')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between iterations in loop mode.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('loop') === true) {
            $interval = $input->getOption('interval');
            (new WorkerLoop())->run(
                function (): void {
                    $this->collector->collect();
                },
                is_numeric($interval) ? (float) $interval : 10.0,
            );

            return Command::SUCCESS;
        }

        $this->collector->collect();

        return Command::SUCCESS;
    }
}
