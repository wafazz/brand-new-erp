<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class PriceStep
{
    public function __construct(
        public PriceSource $source,
        public bool $matched,
        public string $reason,
        public ?string $referenceId = null,
        public ?string $amount = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'label' => $this->source->label(),
            'matched' => $this->matched,
            'reason' => $this->reason,
            'reference_id' => $this->referenceId,
            'amount' => $this->amount,
        ];
    }
}
