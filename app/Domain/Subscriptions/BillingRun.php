<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

final class BillingRun
{
    /** @param array<int, string> $skipped */
    public function __construct(
        public int $billed = 0,
        public int $alreadyBilled = 0,
        public array $skipped = [],
    ) {}

    public function summary(): string
    {
        $parts = ["{$this->billed} billed"];

        if ($this->alreadyBilled > 0) {
            $parts[] = "{$this->alreadyBilled} already billed for that period";
        }

        if ($this->skipped !== []) {
            $parts[] = count($this->skipped).' skipped';
        }

        return implode(', ', $parts).'.';
    }
}
