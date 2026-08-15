<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\RestoreVerifier;
use Illuminate\Console\Command;
use Throwable;

class VerifyBackup extends Command
{
    protected $signature = 'erp:verify-backup {--file=}';

    protected $description = 'Restore the latest dump into a scratch database and prove it is usable.';

    public function handle(RestoreVerifier $verifier): int
    {
        try {
            $report = $verifier->verify($this->option('file'));
        } catch (Throwable $exception) {
            $this->error('Restore rehearsal failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->line($report->explain());

        if (! $report->passed()) {
            $this->error('The restored copy does not match the original. This backup is not trustworthy.');

            return self::FAILURE;
        }

        $this->info('Restore rehearsal passed for '.basename($report->file));

        return self::SUCCESS;
    }
}
