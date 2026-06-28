<?php

declare(strict_types=1);

namespace App\Api\Transfers\Create;

use App\Accounts\Domain\AccountId;
use App\Infrastructure\OpenApi\ResponseStatus;
use App\Infrastructure\OpenApi\Tag;
use App\Messaging\CommandBus;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use App\Transfers\Application\InitiateTransfer;
use App\Transfers\Domain\TransferId;
use App\Transfers\Domain\TransferRepository;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Tag('Transfers')]
final readonly class Action
{
    public function __construct(
        private CommandBus $bus,
        private TransferRepository $transfers,
    ) {}

    #[Route('/transfers', name: 'api_transfers_create', methods: ['POST'])]
    #[ResponseStatus(201)]
    public function __invoke(#[MapRequestPayload] Request $request): Response
    {
        $transferId = TransferId::generate();
        $this->bus->dispatch(new InitiateTransfer(
            $transferId,
            AccountId::fromString($request->sourceAccountId),
            AccountId::fromString($request->destinationAccountId),
            Money::of($request->amount, Currency::of($request->currency)),
        ));

        // The saga runs synchronously; the transfer stream is consistent to read.
        return Response::fromTransfer($this->transfers->load($transferId));
    }
}
