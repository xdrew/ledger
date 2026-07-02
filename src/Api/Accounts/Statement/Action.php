<?php

declare(strict_types=1);

namespace App\Api\Accounts\Statement;

use App\Infrastructure\NlQuery\StatementQueryTranslator;
use App\Infrastructure\OpenApi\QueryParam;
use App\Infrastructure\OpenApi\Tag;
use App\Projections\Query\AccountStatementView;
use App\SharedKernel\Clock\Clock;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Tag('Accounts')]
final readonly class Action
{
    public function __construct(
        private AccountStatementView $statement,
        private StatementQueryTranslator $translator,
        private Clock $clock,
        private bool $llmStatementQueryEnabled,
    ) {}

    #[Route('/accounts/{id}/statement', name: 'api_accounts_statement', methods: ['GET'])]
    #[QueryParam('q', description: 'Natural-language question over the statement (feature-flagged). The response echoes the interpreted filter.')]
    public function __invoke(string $id, #[MapQueryParameter] ?string $q = null): Response
    {
        $q = $q !== null ? trim($q) : null;
        if ($q === null || $q === '') {
            return Response::fromEntries($id, $this->statement->forAccount($id));
        }

        if (!$this->llmStatementQueryEnabled) {
            throw new HttpException(501, 'Natural-language statement queries are not enabled.');
        }

        $filter = $this->translator->translate($q, $this->clock->now());

        return Response::fromFiltered($id, $this->statement->forAccountFiltered($id, $filter), $filter);
    }
}
