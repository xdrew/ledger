<?php

declare(strict_types=1);

namespace App\Observability\Logging;

use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Seeds the correlation id for a request from an inbound `X-Correlation-Id`
 * header (or a fresh id), before other listeners run, so every log line for the
 * request carries it.
 */
final readonly class CorrelationListener
{
    public function __construct(private CorrelationContext $context) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $header = (string) $event->getRequest()->headers->get('X-Correlation-Id');
        $this->context->correlationId = $header !== '' ? $header : Uuid::uuid7()->toString();
    }
}
