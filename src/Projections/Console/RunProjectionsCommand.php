<?php

declare(strict_types=1);

namespace App\Projections\Console;

use App\Projections\ProjectionRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'projections:run', description: 'Catch up the read models from the checkpoint to the latest event.')]
final class RunProjectionsCommand extends Command
{
    public function __construct(private readonly ProjectionRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $processed = $this->runner->run();
        $io->success(\sprintf('Processed %d events; projection lag is now %d.', $processed, $this->runner->lag()));

        return Command::SUCCESS;
    }
}
