<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class BackupService
{
    public function run(): BackupReport
    {
        $directory = (string) config('backup.directory');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the backup directory at {$directory}.");
        }

        $result = Process::path(base_path())
            ->env($this->environment())
            ->timeout(1800)
            ->run(['./scripts/backup.sh', $directory]);

        if (! $result->successful()) {
            throw new RuntimeException('Backup failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $file = trim($result->output());
        $bytes = is_file($file) ? (int) filesize($file) : 0;

        if ($bytes < (int) config('backup.minimum_bytes')) {
            throw new RuntimeException("The dump is only {$bytes} bytes, which is not a backup.");
        }

        return new BackupReport($file, $bytes, $this->prune($directory), $this->copyOffsite($file));
    }

    public function latest(): ?string
    {
        $files = glob(rtrim((string) config('backup.directory'), '/').'/*.dump') ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => [filemtime($b), basename($b)] <=> [filemtime($a), basename($a)]);

        return $files[0];
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'PGPASSWORD' => (string) $connection['password'],
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
        ];
    }

    private function prune(string $directory): int
    {
        $keepDays = (int) config('backup.keep_days');

        if ($keepDays <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $pruned = 0;

        foreach (glob(rtrim($directory, '/').'/*.dump') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function copyOffsite(string $file): ?string
    {
        if (config('backup.offsite.enabled') !== true) {
            return null;
        }

        $command = (string) config('backup.offsite.command');

        if (trim($command) === '') {
            throw new RuntimeException(
                'Offsite backup is enabled but BACKUP_OFFSITE_COMMAND is empty. '.
                'A backup that lives only on the machine it protects is not a backup.'
            );
        }

        $result = Process::path(base_path())->timeout(1800)->run(str_replace('{file}', escapeshellarg($file), $command));

        if (! $result->successful()) {
            throw new RuntimeException('Offsite copy failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $command;
    }
}
