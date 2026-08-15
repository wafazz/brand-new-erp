<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

final readonly class MatchResult
{
    /** @param array<int, MatchDiscrepancy> $discrepancies */
    public function __construct(public bool $matched, public array $discrepancies) {}

    public function reason(): ?string
    {
        if ($this->matched) {
            return null;
        }

        $lines = array_map(
            static fn (MatchDiscrepancy $d): string => $d->reason,
            $this->discrepancies
        );

        return 'This bill does not match the order and the goods received: '.implode(' ', $lines);
    }

    /** @return array<int, array<string, string>> */
    public function toArray(): array
    {
        return array_map(static fn (MatchDiscrepancy $d): array => $d->toArray(), $this->discrepancies);
    }
}
