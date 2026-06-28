<?php

declare(strict_types=1);

namespace App\Api\Accounts\Get;

use App\Projections\Query\AccountBalance;

final readonly class Response implements \JsonSerializable
{
    public function __construct(
        public string $accountId,
        public string $currency,
        public int $available,
        public int $reserved,
        public int $total,
        public int $version,
    ) {}

    public static function fromBalance(AccountBalance $balance): self
    {
        return new self(
            $balance->accountId,
            $balance->total->currency->code,
            $balance->available->minorUnits,
            $balance->reserved->minorUnits,
            $balance->total->minorUnits,
            $balance->version,
        );
    }

    /**
     * @return array{accountId: string, currency: string, available: int, reserved: int, total: int, version: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'accountId' => $this->accountId,
            'currency' => $this->currency,
            'available' => $this->available,
            'reserved' => $this->reserved,
            'total' => $this->total,
            'version' => $this->version,
        ];
    }
}
