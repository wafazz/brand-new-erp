<?php

declare(strict_types=1);

namespace App\Domain\Backup;

final readonly class BackupReport
{
    public function __construct(
        public string $file,
        public int $bytes,
        public int $pruned,
        public ?string $offsite,
    ) {}

    public function explain(): string
    {
        $size = number_format($this->bytes / 1024, 1).' KB';
        $offsite = $this->offsite ?? 'not configured';

        return "Wrote {$this->file} ({$size}), pruned {$this->pruned} old dump(s), offsite: {$offsite}.";
    }
}
