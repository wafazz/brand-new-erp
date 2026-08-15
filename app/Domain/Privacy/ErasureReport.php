<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

final readonly class ErasureReport
{
    /**
     * @param  array<string, int>  $anonymised
     * @param  array<string, int>  $retained
     */
    public function __construct(
        public string $subject,
        public array $anonymised,
        public array $retained,
        public string $reason,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'anonymised' => $this->anonymised,
            'retained' => $this->retained,
            'reason' => $this->reason,
            'explanation' => $this->explain(),
        ];
    }

    public function explain(): string
    {
        $anonymised = collect($this->anonymised)
            ->map(fn (int $count, string $table): string => "{$count} {$table}")
            ->implode(', ');

        $retained = collect($this->retained)
            ->map(fn (int $count, string $table): string => "{$count} {$table}")
            ->implode(', ');

        return "Erased personal data for {$this->subject}. Anonymised: {$anonymised}. ".
            "Retained under accounting obligation: {$retained}. Reason: {$this->reason}";
    }
}
