<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

final readonly class MatchDiscrepancy
{
    public function __construct(
        public string $sku,
        public string $kind,
        public string $reason,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['sku' => $this->sku, 'kind' => $this->kind, 'reason' => $this->reason];
    }
}
