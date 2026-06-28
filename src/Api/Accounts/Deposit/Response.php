<?php

declare(strict_types=1);

namespace App\Api\Accounts\Deposit;

final readonly class Response implements \JsonSerializable
{
    public function __construct(
        public string $accountId,
        public int $amount,
        public string $currency,
        public string $status = 'accepted',
    ) {}

    /**
     * @return array{accountId: string, amount: int, currency: string, status: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'accountId' => $this->accountId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
        ];
    }
}
