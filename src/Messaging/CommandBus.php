<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Accounts\Application\DepositFunds;
use App\Accounts\Application\DepositFundsHandler;
use App\Accounts\Application\OpenAccount;
use App\Accounts\Application\OpenAccountHandler;
use App\Observability\Logging\CorrelationContext;
use App\Observability\Tracing\NoopTracer;
use App\Observability\Tracing\Tracer;
use App\SharedKernel\Clock\Clock;
use App\Transfers\Application\InitiateTransfer;
use App\Transfers\Application\InitiateTransferHandler;
use Ramsey\Uuid\Uuid;
use Thesis\MessageBus\Context;
use Thesis\MessageBus\Handlers;
use Thesis\MessageBus\Metadata;
use Thesis\MessageBus\Metadata\Kind;

/**
 * Synchronous, in-process command bus over thesis/message-bus's core handler
 * routing. Commands are handled immediately and return nothing; correlation is
 * carried in the message Metadata (conversation id) for handlers to thread into
 * event metadata. The async transport (Pgmq/NATS) is a future swap that needs no
 * handler changes.
 *
 * @phpstan-type Endpoint non-empty-string
 */
final readonly class CommandBus
{
    private const string ORIGIN = 'ledger';
    private const string ENDPOINT = 'ledger';

    /** @var Handlers<NoTransaction> */
    private Handlers $handlers;

    private Tracer $tracer;

    public function __construct(
        OpenAccountHandler $openAccount,
        DepositFundsHandler $depositFunds,
        InitiateTransferHandler $initiateTransfer,
        private Clock $clock,
        private ?CorrelationContext $correlation = null,
        ?Tracer $tracer = null,
    ) {
        $this->tracer = $tracer ?? new NoopTracer();
        $this->handlers = new Handlers()
            ->with(OpenAccount::class, $openAccount(...))
            ->with(DepositFunds::class, $depositFunds(...))
            ->with(InitiateTransfer::class, $initiateTransfer(...));
    }

    public function dispatch(object $command, ?string $correlationId = null): void
    {
        $id = Uuid::uuid7()->toString();
        $correlationId ??= $this->correlation?->correlationId;
        $conversationId = $correlationId !== null && $correlationId !== '' ? $correlationId : $id;

        $metadata = new Metadata(
            id: $id,
            conversationId: $conversationId,
            causeId: null,
            kind: Kind::Command,
            origin: self::ORIGIN,
            createdAt: $this->clock->now(),
        );

        $this->tracer->span(
            'command.dispatch',
            fn() => $this->handlers->handle($command, new Context(self::ENDPOINT, $metadata, new NoTransaction())),
            ['command' => $command::class],
        );
    }
}
