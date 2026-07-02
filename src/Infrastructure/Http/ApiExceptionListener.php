<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Accounts\Domain\Exception\AccountNotActive;
use App\Accounts\Domain\Exception\AccountNotFound;
use App\Accounts\Domain\Exception\InsufficientFunds;
use App\Accounts\Domain\Exception\InvalidAmount;
use App\EventStore\ConcurrencyConflict;
use App\Infrastructure\NlQuery\TranslationFailed;
use App\Ledger\Domain\Exception\ClosedAccountPosting;
use App\Ledger\Domain\Exception\InvalidLegAmount;
use App\Ledger\Domain\Exception\JournalEntryNotFound;
use App\Ledger\Domain\Exception\UnbalancedEntry;
use App\SharedKernel\Money\CurrencyMismatch;
use App\Transfers\Domain\Exception\InvalidTransferTransition;
use App\Transfers\Domain\Exception\TransferNotFound;
use App\Transfers\Domain\Exception\TransferNotReversible;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Maps throwables raised while handling `/api` requests to RFC 9457 problem+json
 * (D5). Ledger domain exceptions all extend \RuntimeException, so the mapping is
 * explicit per exception rather than by a shared base class.
 */
// Runs below Symfony Security's firewall exception listener (priority 1) so that
// an unauthenticated request is turned into a 401 by the entry point first; we
// only format what security did not already handle.
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final readonly class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api') || $event->hasResponse()) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $this->statusFor($exception);

        $validation = $this->validationFailure($exception);
        if ($validation !== null) {
            $event->setResponse(ProblemDetails::response(422, 'Validation failed.', $this->fieldErrors($validation)));

            return;
        }

        // Only the catch-all 500 masks its message (unknown internals); deliberate
        // 5xx like 501 (feature disabled) and 502 (translation failed) are ours.
        $detail = $status === 500 ? 'An unexpected error occurred.' : $exception->getMessage();
        $event->setResponse(ProblemDetails::response($status, $detail));
    }

    private function statusFor(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AccessDeniedException => 403,
            $exception instanceof AccountNotFound,
            $exception instanceof TransferNotFound,
            $exception instanceof JournalEntryNotFound => 404,
            $exception instanceof InsufficientFunds,
            $exception instanceof AccountNotActive,
            $exception instanceof ClosedAccountPosting,
            $exception instanceof InvalidTransferTransition,
            $exception instanceof TransferNotReversible,
            $exception instanceof ConcurrencyConflict => 409,
            $exception instanceof InvalidAmount,
            $exception instanceof CurrencyMismatch,
            $exception instanceof InvalidLegAmount,
            $exception instanceof UnbalancedEntry,
            $exception instanceof \InvalidArgumentException => 422,
            $exception instanceof TranslationFailed => 502,
            default => 500,
        };
    }

    private function validationFailure(\Throwable $exception): ?ValidationFailedException
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ValidationFailedException) {
                return $current;
            }
        }

        return null;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function fieldErrors(ValidationFailedException $exception): array
    {
        $errors = [];
        foreach ($exception->getViolations() as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $errors;
    }
}
