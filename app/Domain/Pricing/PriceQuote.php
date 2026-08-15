<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Support\Money;

final readonly class PriceQuote
{
    /**
     * @param  array<int, PriceStep>  $trail
     * @param  array<int, array<string, mixed>>  $promotions
     */
    public function __construct(
        public Money $unitPrice,
        public Money $base,
        public Money $discount,
        public PriceSource $source,
        public ?string $sourceId,
        public array $trail,
        public array $promotions,
    ) {}

    public function explain(): string
    {
        $line = $this->source->label().' '.$this->base->format();

        if (! $this->discount->isZero()) {
            $names = array_map(static fn (array $p): string => (string) $p['name'], $this->promotions);
            $line .= ' less '.$this->discount->format().' ('.implode(', ', $names).')';
        }

        return $line.' = '.$this->unitPrice->format();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unit_price' => $this->unitPrice->toDecimal(),
            'base' => $this->base->toDecimal(),
            'discount' => $this->discount->toDecimal(),
            'currency' => $this->unitPrice->currency,
            'source' => $this->source->value,
            'source_id' => $this->sourceId,
            'explanation' => $this->explain(),
            'promotions' => $this->promotions,
            'trail' => array_map(static fn (PriceStep $step): array => $step->toArray(), $this->trail),
        ];
    }
}
