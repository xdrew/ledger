<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\OpenApi\ResponseStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets `Action` controllers return their `JsonSerializable` `Response` DTO (which
 * is what the OpenAPI generator reflects on) instead of a framework `Response`:
 * this converts the DTO into a `JsonResponse`, applying the success status from
 * the action's `#[ResponseStatus]` attribute.
 */
final class ControllerResponseListener
{
    private const string STATUS_ATTRIBUTE = '_success_status';

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        $reflection = $this->reflectController($event->getController());
        if ($reflection === null) {
            return;
        }

        $attributes = $reflection->getAttributes(ResponseStatus::class);
        if ($attributes !== []) {
            $event->getRequest()->attributes->set(self::STATUS_ATTRIBUTE, $attributes[0]->newInstance()->code);
        }
    }

    #[AsEventListener(event: KernelEvents::VIEW)]
    public function onView(ViewEvent $event): void
    {
        $result = $event->getControllerResult();
        if (!$result instanceof \JsonSerializable) {
            return;
        }

        $status = $event->getRequest()->attributes->getInt(self::STATUS_ATTRIBUTE, 200);
        $event->setResponse(new JsonResponse($result, $status));
    }

    private function reflectController(callable $controller): ?\ReflectionMethod
    {
        if (\is_array($controller)) {
            [$object, $method] = $controller;

            return new \ReflectionMethod($object, $method);
        }

        if (\is_object($controller) && !$controller instanceof \Closure) {
            return new \ReflectionMethod($controller, '__invoke');
        }

        return null;
    }
}
