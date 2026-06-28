<?php

declare(strict_types=1);

namespace App\Api\Transfers\Get;

use App\Transfers\Domain\Transfer;

final readonly class Response implements \JsonSerializable
{
    public function __construct(
        public string $transferId,
        public string $status,
        public string $sourceAccountId,
        public string $destinationAccountId,
        public int $amount,
        public string $currency,
        public ?string $failureReason,
    ) {}

    public static function fromTransfer(Transfer $transfer): self
    {
        return new self(
            $transfer->id()->toString(),
            $transfer->status()->value,
            $transfer->sourceAccountId(),
            $transfer->destinationAccountId(),
            $transfer->amount()->minorUnits,
            $transfer->amount()->currency->code,
            $transfer->failureReason()?->value,
        );
    }

    /**
     * @return array{transferId: string, status: string, sourceAccountId: string, destinationAccountId: string, amount: int, currency: string, failureReason: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'transferId' => $this->transferId,
            'status' => $this->status,
            'sourceAccountId' => $this->sourceAccountId,
            'destinationAccountId' => $this->destinationAccountId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'failureReason' => $this->failureReason,
        ];
    }
}
