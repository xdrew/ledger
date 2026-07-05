<?php

declare(strict_types=1);

namespace App\Api\Accounts\Events;

use App\Api\EventStream\Response;
use App\EventStore\EventStore;
use App\EventStore\StreamId;
use App\Infrastructure\OpenApi\Tag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of an account's recorded event stream.
 */
#[Tag('Accounts')]
final readonly class Action
{
    public function __construct(private EventStore $eventStore) {}

    #[Route('/accounts/{id}/events', name: 'api_accounts_events', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $events = $this->eventStore->load(StreamId::of('account', $id));
        if ($events === []) {
            throw new NotFoundHttpException(\sprintf('Account "%s" was not found.', $id));
        }

        return Response::fromRecordedEvents('account', $id, $events);
    }
}
