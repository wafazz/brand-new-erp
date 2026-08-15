<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RunBackup extends Command
{
    protected $signature = 'erp:backup';

    protected $description = 'Dump the database, prune old dumps and copy the result offsite.';

    public function handle(BackupService $backups): int
    {
        try {
            $report = $backups->run();
        } catch (Throwable $exception) {
            $this->error('Backup failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->line($report->explain());

        return self::SUCCESS;
    }
}
