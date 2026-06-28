<?php

declare(strict_types=1);

namespace App\Api\Transfers\Get;

use App\Infrastructure\OpenApi\Tag;
use App\Transfers\Domain\TransferId;
use App\Transfers\Domain\TransferRepository;
use Symfony\Component\Routing\Attribute\Route;

#[Tag('Transfers')]
final readonly class Action
{
    public function __construct(private TransferRepository $transfers) {}

    #[Route('/transfers/{id}', name: 'api_transfers_get', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        return Response::fromTransfer($this->transfers->load(TransferId::fromString($id)));
    }
}
