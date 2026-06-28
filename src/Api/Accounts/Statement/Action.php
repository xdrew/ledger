<?php

declare(strict_types=1);

namespace App\Api\Accounts\Statement;

use App\Infrastructure\OpenApi\Tag;
use App\Projections\Query\AccountStatementView;
use Symfony\Component\Routing\Attribute\Route;

#[Tag('Accounts')]
final readonly class Action
{
    public function __construct(private AccountStatementView $statement) {}

    #[Route('/accounts/{id}/statement', name: 'api_accounts_statement', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        return Response::fromEntries($id, $this->statement->forAccount($id));
    }
}
