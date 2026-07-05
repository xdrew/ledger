<?php

declare(strict_types=1);

namespace App\Api\Transfers\Events;

use App\Api\EventStream\Response;
use App\EventStore\EventStore;
use App\EventStore\StreamId;
use App\Infrastructure\OpenApi\Tag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of a transfer saga's recorded event stream — the
 * Initiated → Held → Posted → Completed/Failed trail.
 */
#[Tag('Transfers')]
final readonly class Action
{
    public function __construct(private EventStore $eventStore) {}

    #[Route('/transfers/{id}/events', name: 'api_transfers_events', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $events = $this->eventStore->load(StreamId::of('transfer', $id));
        if ($events === []) {
            throw new NotFoundHttpException(\sprintf('Transfer "%s" was not found.', $id));
        }

        return Response::fromRecordedEvents('transfer', $id, $events);
    }
}
