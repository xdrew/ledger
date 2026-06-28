<?php

declare(strict_types=1);

namespace App\Api\Transfers\Create;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $sourceAccountId = '',
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $destinationAccountId = '',
        #[Assert\Positive(message: 'Amount must be a positive number of minor units.')]
        public int $amount = 0,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[A-Z]{3}$/', message: 'Currency must be a 3-letter uppercase code.')]
        public string $currency = '',
    ) {}
}
