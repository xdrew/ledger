<?php

declare(strict_types=1);

namespace App\Accounts\Application;

use App\Accounts\Domain\Account;
use App\Accounts\Domain\AccountRepository;
use App\EventStore\EventMetadata;
use Thesis\MessageBus\Context;

final readonly class OpenAccountHandler
{
    public function __construct(private AccountRepository $accounts) {}

    public function __invoke(OpenAccount $command, Context $context): void
    {
        $account = Account::open($command->accountId, $command->currency);
        $this->accounts->save($account, $this->metadata($context));
    }

    private function metadata(Context $context): EventMetadata
    {
        return new EventMetadata($context->metadata->conversationId, $context->metadata->id);
    }
}
