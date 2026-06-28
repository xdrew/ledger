<?php

declare(strict_types=1);

namespace App\Accounts\Application;

use App\Accounts\Domain\AccountRepository;
use App\EventStore\EventMetadata;
use Thesis\MessageBus\Context;

final readonly class DepositFundsHandler
{
    public function __construct(private AccountRepository $accounts) {}

    public function __invoke(DepositFunds $command, Context $context): void
    {
        $account = $this->accounts->load($command->accountId);
        $account->deposit($command->amount);
        $this->accounts->save($account, new EventMetadata($context->metadata->conversationId, $context->metadata->id));
    }
}
