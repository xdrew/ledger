<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Idempotency\IdempotencyKey;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\Outcome\Completed;
use App\Idempotency\Outcome\InProgress;
use App\Idempotency\Outcome\Mismatch;
use App\Idempotency\StoredResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes mutating `/api` requests idempotent via the `Idempotency-Key` header and
 * the {@see IdempotencyStore}: reserve on the way in, replay a completed key,
 * reject in-flight (`409`) and reused-key/different-payload (`422`), and capture
 * the response to complete the key on the way out. Runs after the firewall so
 * unauthenticated requests never reserve a key.
 */
final class IdempotencyListener
{
    private const HEADER = 'Idempotency-Key';
    private const ATTRIBUTE = '_idempotency';
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly IdempotencyStore $store) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isMutatingApiRequest($request)) {
            return;
        }

        $headerValue = (string) $request->headers->get(self::HEADER);
        if ($headerValue === '') {
            $event->setResponse(ProblemDetails::response(400, 'The Idempotency-Key header is required.'));

            return;
        }

        $key = IdempotencyKey::fromString($headerValue);
        $route = $this->route($request);
        $outcome = $this->store->begin($key, $route, $this->requestHash($request));

        switch (true) {
            case $outcome instanceof Completed:
                $event->setResponse($this->replay($outcome->response));

                break;
            case $outcome instanceof InProgress:
                $event->setResponse(ProblemDetails::response(409, 'A request with this Idempotency-Key is already in progress.'));

                break;
            case $outcome instanceof Mismatch:
                $event->setResponse(ProblemDetails::response(422, 'This Idempotency-Key was already used with a different request.'));

                break;

            default:
                $request->attributes->set(self::ATTRIBUTE, ['key' => $key, 'route' => $route]);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -4)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $reservation = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!\is_array($reservation)) {
            return;
        }

        $response = $event->getResponse();
        // Do not memoize server errors: a retry should get a fresh attempt.
        if ($response->getStatusCode() >= 500) {
            return;
        }

        $key = $reservation['key'];
        $route = $reservation['route'];
        \assert($key instanceof IdempotencyKey && \is_string($route));

        $contentType = $response->headers->get('Content-Type', 'application/json');
        $this->store->complete($key, $route, new StoredResponse(
            $response->getStatusCode(),
            ['Content-Type' => (string) $contentType],
            (string) $response->getContent(),
        ));
    }

    private function isMutatingApiRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api')
            && \in_array($request->getMethod(), self::MUTATING_METHODS, true);
    }

    private function route(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) && $route !== '' ? $route : $request->getPathInfo();
    }

    private function requestHash(Request $request): string
    {
        return hash('sha256', $request->getMethod() . ' ' . $request->getPathInfo() . ' ' . $request->getContent());
    }

    private function replay(StoredResponse $stored): Response
    {
        $headers = $stored->headers + ['Idempotent-Replayed' => 'true'];

        return new Response($stored->body, $stored->status, $headers);
    }
}
