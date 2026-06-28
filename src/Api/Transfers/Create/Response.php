<?php

declare(strict_types=1);

namespace App\Api\Transfers\Create;

use App\Transfers\Domain\Transfer;

final readonly class Response implements \JsonSerializable
{
    public function __construct(
        public string $transferId,
        public string $status,
        public ?string $failureReason,
    ) {}

    public static function fromTransfer(Transfer $transfer): self
    {
        return new self(
            $transfer->id()->toString(),
            $transfer->status()->value,
            $transfer->failureReason()?->value,
        );
    }

    /**
     * @return array{transferId: string, status: string, failureReason: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'transferId' => $this->transferId,
            'status' => $this->status,
            'failureReason' => $this->failureReason,
        ];
    }
}
