<?php

declare(strict_types=1);

namespace App\Accounts\Domain;

enum AccountStatus: string
{
    case Open = 'open';
    case Frozen = 'frozen';
    case Closed = 'closed';
}
