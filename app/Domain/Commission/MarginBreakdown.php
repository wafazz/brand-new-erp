<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Support\Money;

final readonly class MarginBreakdown
{
    /** @param array<int, MarginComponent> $components */
    public function __construct(
        public Money $sales,
        public Money $cost,
        public Money $shipping,
        public Money $fees,
        public Money $adSpend,
        public Money $margin,
        public bool $isProvisional,
        public array $components,
    ) {}

    public function explain(): string
    {
        $parts = [];

        foreach ($this->components as $component) {
            if ($component->sign === '+') {
                $parts[] = "{$component->label} {$component->amount->format()}";

                continue;
            }

            if (! $component->amount->isZero()) {
                $parts[] = "{$component->label} {$component->amount->format()}";
            }
        }

        return implode(' − ', $parts).' = '.$this->margin->format();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sales' => $this->sales->toDecimal(),
            'cost' => $this->cost->toDecimal(),
            'shipping' => $this->shipping->toDecimal(),
            'fees' => $this->fees->toDecimal(),
            'ad_spend' => $this->adSpend->toDecimal(),
            'margin' => $this->margin->toDecimal(),
            'currency' => $this->margin->currency,
            'is_provisional' => $this->isProvisional,
            'explanation' => $this->explain(),
            'components' => array_map(
                static fn (MarginComponent $c): array => $c->toArray(),
                $this->components
            ),
        ];
    }
}
