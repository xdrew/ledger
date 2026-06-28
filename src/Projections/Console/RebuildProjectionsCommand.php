<?php

declare(strict_types=1);

namespace App\Projections\Console;

use App\Projections\ProjectionRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'projections:rebuild', description: 'Truncate read models and replay all events from the event store.')]
final class RebuildProjectionsCommand extends Command
{
    public function __construct(private readonly ProjectionRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $processed = $this->runner->rebuild();
        $io->success(\sprintf('Projections rebuilt; processed %d events.', $processed));

        return Command::SUCCESS;
    }
}
