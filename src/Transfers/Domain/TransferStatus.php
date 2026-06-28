<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

enum TransferStatus: string
{
    case Initiated = 'initiated';
    case Held = 'held';
    case Posted = 'posted';
    case Completed = 'completed';
    case Failed = 'failed';
}
