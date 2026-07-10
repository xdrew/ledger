<?php

declare(strict_types=1);

namespace App\SharedKernel\Money;

/**
 * ISO-4217-style currency code (three uppercase letters). Single-currency
 * accounts means every monetary amount carries exactly one of these.
 */
final readonly class Currency
{
    private function __construct(public string $code) {}

    public static function of(string $code): self
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid currency code: "%s".', $code));
        }

        return new self($code);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
