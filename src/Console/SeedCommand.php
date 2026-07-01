<?php

declare(strict_types=1);

namespace App\Console;

use App\Accounts\Application\DepositFunds;
use App\Accounts\Application\OpenAccount;
use App\Accounts\Domain\AccountId;
use App\Messaging\CommandBus;
use App\Projections\ProjectionRunner;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Transfers\Application\InitiateTransfer;
use App\Transfers\Domain\TransferId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Populates a fresh environment with demo accounts and transfers (through the
 * command bus) and catches the read models up, so `docker compose up` yields a
 * system with data to look at. Safe to re-run — it creates fresh ids each time.
 */
#[AsCommand(name: 'app:seed', description: 'Create demo accounts and transfers.')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly CommandBus $bus,
        private readonly ProjectionRunner $projections,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $usd = static fn(int $minor): Money => Money::of($minor, Currency::of('USD'));

        $alice = AccountId::generate();
        $bob = AccountId::generate();
        $carol = AccountId::generate();

        foreach ([$alice, $bob, $carol] as $account) {
            $this->bus->dispatch(new OpenAccount($account, Currency::of('USD')));
        }

        $this->bus->dispatch(new DepositFunds($alice, $usd(10_000)));
        $this->bus->dispatch(new DepositFunds($bob, $usd(5_000)));

        // A funded transfer completes; an unfunded one fails (status: failed).
        $this->bus->dispatch(new InitiateTransfer(TransferId::generate(), $alice, $bob, $usd(3_000)));
        $this->bus->dispatch(new InitiateTransfer(TransferId::generate(), $carol, $alice, $usd(1_000)));

        $this->projections->run();

        $io->success('Seeded 3 accounts and 2 transfers (1 completed, 1 failed).');
        $io->listing([
            \sprintf('alice: %s', $alice->toString()),
            \sprintf('bob:   %s', $bob->toString()),
            \sprintf('carol: %s', $carol->toString()),
        ]);

        return Command::SUCCESS;
    }
}
