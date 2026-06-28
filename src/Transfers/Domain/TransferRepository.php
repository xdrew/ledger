<?php

declare(strict_types=1);

namespace App\Transfers\Domain;

use App\EventStore\EventMetadata;
use App\Transfers\Domain\Exception\TransferNotFound;

interface TransferRepository
{
    public function save(Transfer $transfer, ?EventMetadata $metadata = null): void;

    /**
     * @throws TransferNotFound when no stream exists for the id
     */
    public function load(TransferId $id): Transfer;
}
