<?php

declare(strict_types=1);

namespace App\Api\Accounts\Open;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[A-Z]{3}$/', message: 'Currency must be a 3-letter uppercase code.')]
        public string $currency = '',
    ) {}
}
