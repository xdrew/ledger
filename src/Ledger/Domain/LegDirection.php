<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

enum LegDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
