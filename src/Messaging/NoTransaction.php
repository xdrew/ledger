<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * Placeholder transaction for the in-process command bus. thesis/message-bus's
 * Context is generic over a transaction type; commands here manage their own
 * persistence via repositories, so no real transaction object is needed.
 */
final class NoTransaction {}
