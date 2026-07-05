<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

enum FailureReason: string
{
    case InsufficientFunds = 'insufficient_funds';
    case ClosedAccount = 'closed_account';
    case UnknownAccount = 'unknown_account';
    case Conflict = 'conflict';
    case Other = 'other';
}
