<?php

declare(strict_types=1);

namespace App\Domain\Backup;

final readonly class VerificationReport
{
    /** @param array<string, array{original: int, restored: int}> $counts */
    public function __construct(
        public string $file,
        public array $counts,
        public bool $appendOnlyHeld,
    ) {}

    public function passed(): bool
    {
        if (! $this->appendOnlyHeld) {
            return false;
        }

        foreach ($this->counts as $pair) {
            if ($pair['original'] !== $pair['restored']) {
                return false;
            }
        }

        return true;
    }

    public function explain(): string
    {
        $lines = [];

        foreach ($this->counts as $what => $pair) {
            $mark = $pair['original'] === $pair['restored'] ? 'ok' : 'MISMATCH';
            $lines[] = "{$what} {$pair['original']}/{$pair['restored']} {$mark}";
        }

        $lines[] = 'append-only '.($this->appendOnlyHeld ? 'held' : 'LOST');

        return implode(' · ', $lines);
    }
}
