<?php

declare(strict_types=1);

namespace App\Projections\Console;

use App\Projections\ProjectionRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'projections:status', description: 'Show the projection checkpoint, head position, and lag.')]
final class ProjectionsStatusCommand extends Command
{
    public function __construct(private readonly ProjectionRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->table(
            ['Checkpoint', 'Head', 'Lag'],
            [[(string) $this->runner->position(), (string) $this->runner->head(), (string) $this->runner->lag()]],
        );

        return Command::SUCCESS;
    }
}
