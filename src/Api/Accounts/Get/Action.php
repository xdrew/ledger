<?php

declare(strict_types=1);

namespace App\Api\Accounts\Get;

use App\Infrastructure\OpenApi\Tag;
use App\Projections\Query\AccountBalanceView;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Tag('Accounts')]
final readonly class Action
{
    public function __construct(private AccountBalanceView $balances) {}

    #[Route('/accounts/{id}', name: 'api_accounts_get', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $balance = $this->balances->find($id);
        if ($balance === null) {
            throw new NotFoundHttpException(\sprintf('Account "%s" was not found.', $id));
        }

        return Response::fromBalance($balance);
    }
}
