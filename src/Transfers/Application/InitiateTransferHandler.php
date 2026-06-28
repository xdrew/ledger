<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use Thesis\MessageBus\Context;

/**
 * Handles the InitiateTransfer command by running the transfer saga.
 *
 * (Threading the correlation id from $context through the saga's many saves is a
 * follow-up; account commands establish the correlation pattern.)
 */
final readonly class InitiateTransferHandler
{
    public function __construct(private TransferOrchestrator $orchestrator) {}

    public function __invoke(InitiateTransfer $command, Context $context): void
    {
        $this->orchestrator->initiate($command);
    }
}
