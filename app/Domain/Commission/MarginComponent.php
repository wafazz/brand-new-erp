<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Support\Money;

final readonly class MarginComponent
{
    public function __construct(
        public string $key,
        public string $label,
        public Money $amount,
        public string $sign,
        public string $basis,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'amount' => $this->amount->toDecimal(),
            'sign' => $this->sign,
            'basis' => $this->basis,
        ];
    }
}
