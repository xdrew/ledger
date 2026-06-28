<?php

declare(strict_types=1);

namespace App\Api\Accounts\Open;

use App\Accounts\Application\OpenAccount;
use App\Accounts\Domain\AccountId;
use App\Infrastructure\OpenApi\ResponseStatus;
use App\Infrastructure\OpenApi\Tag;
use App\Messaging\CommandBus;
use App\SharedKernel\Money\Currency;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Tag('Accounts')]
final readonly class Action
{
    public function __construct(private CommandBus $bus) {}

    #[Route('/accounts', name: 'api_accounts_open', methods: ['POST'])]
    #[ResponseStatus(201)]
    public function __invoke(#[MapRequestPayload] Request $request): Response
    {
        $accountId = AccountId::generate();
        $this->bus->dispatch(new OpenAccount($accountId, Currency::of($request->currency)));

        return new Response($accountId->toString(), $request->currency);
    }
}
