<?php

declare(strict_types=1);

namespace App\Outbox\Console;

use App\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs one catch-up pass of the outbox relay. A continuous worker loop (and a
 * RoadRunner job host) arrive with deployment.
 */
#[AsCommand(name: 'outbox:relay', description: 'Publish pending domain events from the outbox to the transport.')]
final class RelayCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $published = $this->relay->relay();
        $io->success(\sprintf('Published %d events; relay checkpoint is now %d.', $published, $this->relay->position()));

        return Command::SUCCESS;
    }
}
