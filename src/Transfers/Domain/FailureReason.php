<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

enum FailureReason: string
{
    case InsufficientFunds = 'insufficient_funds';
    case ClosedAccount = 'closed_account';
    case FrozenAccount = 'frozen_account';
    case UnknownAccount = 'unknown_account';
    case CurrencyMismatch = 'currency_mismatch';
    case Conflict = 'conflict';
    case Other = 'other';
}
