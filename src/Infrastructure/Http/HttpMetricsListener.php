<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Observability\Metrics\Metric;
use App\Observability\Metrics\Metrics;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records the RED signals for HTTP traffic — a request counter labelled by
 * method and status, and a latency histogram — so the Grafana panels have
 * app-emitted series (RoadRunner's plugin exposes only worker-pool metrics,
 * not per-request counts). Stamps the start as early as possible and observes
 * on the way out, covering short-circuited responses (e.g. a 400 from the
 * idempotency guard) as well as controller responses.
 */
final readonly class HttpMetricsListener
{
    private const string START_ATTRIBUTE = '_metrics_start';

    public function __construct(private Metrics $metrics) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 10_000)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::START_ATTRIBUTE, microtime(true));
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10_000)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $method = $request->getMethod();
        $status = (string) $event->getResponse()->getStatusCode();

        $this->metrics->incrementCounter(Metric::HTTP_REQUESTS_TOTAL, ['method' => $method, 'status' => $status]);

        $start = $request->attributes->get(self::START_ATTRIBUTE);
        if (\is_float($start)) {
            $this->metrics->observeHistogram(Metric::HTTP_REQUEST_DURATION_SECONDS, microtime(true) - $start);
        }
    }
}
