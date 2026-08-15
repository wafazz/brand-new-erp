<?php

declare(strict_types=1);

namespace App\Domain\Payments;

final class SweepResult
{
    /** @param array<int, string> $failed */
    public function __construct(
        public int $raised = 0,
        public array $failed = [],
        public bool $skippedUnconfigured = false,
    ) {}

    public function summary(): string
    {
        if ($this->skippedUnconfigured) {
            return 'Billplz is not configured; no payment links raised.';
        }

        $parts = ["{$this->raised} payment link".($this->raised === 1 ? '' : 's').' raised'];

        if ($this->failed !== []) {
            $parts[] = count($this->failed).' failed';
        }

        return implode(', ', $parts).'.';
    }
}
