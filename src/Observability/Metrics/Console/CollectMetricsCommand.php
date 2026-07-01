<?php

declare(strict_types=1);

namespace App\Observability\Metrics\Console;

use App\Observability\Metrics\GaugeCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Refreshes the DB-derived gauges. Run on an interval by the RoadRunner service
 * scheduler so `outbox_pending` / `projection_lag_seconds` / `holds_active` stay
 * current for Prometheus scrapes.
 */
#[AsCommand(name: 'metrics:collect', description: 'Refresh the DB-derived observability gauges.')]
final class CollectMetricsCommand extends Command
{
    public function __construct(private readonly GaugeCollector $collector)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->collector->collect();

        return Command::SUCCESS;
    }
}
