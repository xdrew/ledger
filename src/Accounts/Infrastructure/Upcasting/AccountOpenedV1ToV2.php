<?php

declare(strict_types=1);

namespace App\Accounts\Infrastructure\Upcasting;

use App\Accounts\Domain\Event\AccountOpened;
use App\EventStore\Serialization\Upcaster;

/**
 * AccountOpened v1 → v2: v1 payloads predate the `account_type` field; every
 * account opened before the field existed is, by definition, a standard account,
 * so the default is derivable (ADR-006's condition for an additive upcast).
 */
final class AccountOpenedV1ToV2 implements Upcaster
{
    public function eventType(): string
    {
        return 'accounts.account_opened';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function upcast(array $payload): array
    {
        $payload['account_type'] = AccountOpened::DEFAULT_ACCOUNT_TYPE;

        return $payload;
    }
}
