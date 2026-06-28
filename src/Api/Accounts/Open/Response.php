<?php

declare(strict_types=1);

namespace App\Api\Accounts\Open;

final readonly class Response implements \JsonSerializable
{
    public function __construct(
        public string $accountId,
        public string $currency,
    ) {}

    /**
     * @return array{accountId: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['accountId' => $this->accountId, 'currency' => $this->currency];
    }
}
