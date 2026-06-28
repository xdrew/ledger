<?php

declare(strict_types=1);

namespace App\Api\Accounts\Deposit;

use App\Accounts\Application\DepositFunds;
use App\Accounts\Domain\AccountId;
use App\Infrastructure\OpenApi\Tag;
use App\Messaging\CommandBus;
use App\SharedKernel\Money\Currency;
use App\SharedKernel\Money\Money;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Tag('Accounts')]
final readonly class Action
{
    public function __construct(private CommandBus $bus) {}

    #[Route('/accounts/{id}/deposits', name: 'api_accounts_deposit', methods: ['POST'])]
    public function __invoke(string $id, #[MapRequestPayload] Request $request): Response
    {
        $amount = Money::of($request->amount, Currency::of($request->currency));
        $this->bus->dispatch(new DepositFunds(AccountId::fromString($id), $amount));

        return new Response($id, $request->amount, $request->currency);
    }
}
