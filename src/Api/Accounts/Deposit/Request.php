<?php

declare(strict_types=1);

namespace App\Api\Accounts\Deposit;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\Positive(message: 'Amount must be a positive number of minor units.')]
        public int $amount = 0,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[A-Z]{3}$/', message: 'Currency must be a 3-letter uppercase code.')]
        public string $currency = '',
    ) {}
}
