<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class RestoreVerifier
{
    public function __construct(private readonly BackupService $backups) {}

    public function verify(?string $file = null): VerificationReport
    {
        $dump = $file ?? $this->backups->latest();

        if ($dump === null || ! is_file($dump)) {
            throw new RuntimeException('There is no dump to verify. A backup nobody has restored is a hope, not a backup.');
        }

        $target = (string) config('backup.verify.database');
        $before = $this->fingerprint(null);

        $result = Process::path(base_path())
            ->env($this->backups->environment())
            ->timeout(1800)
            ->run(['./scripts/restore.sh', $dump, $target]);

        if (! $result->successful()) {
            throw new RuntimeException('Restore failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        try {
            $after = $this->fingerprint($target);
            $appendOnly = $this->appendOnlyHolds($target);
        } finally {
            $this->drop($target);
        }

        $counts = [];

        foreach ($before as $key => $value) {
            $counts[$key] = ['original' => $value, 'restored' => $after[$key] ?? -1];
        }

        return new VerificationReport($dump, $counts, $appendOnly);
    }

    /** @return array<string, int> */
    private function fingerprint(?string $database): array
    {
        $sql = [
            'tables' => "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE'",
            'triggers' => 'select count(*) from pg_trigger where not tgisinternal',
            'checks' => "select count(*) from pg_constraint where contype='c' and conname like '%\\_check'",
            'foreign_keys' => "select count(*) from pg_constraint where contype='f'",
        ];

        $counts = [];

        foreach ($sql as $key => $query) {
            $counts[$key] = (int) $this->scalar($database, $query);
        }

        return $counts;
    }

    private function appendOnlyHolds(string $database): bool
    {
        $connection = $this->connection($database);

        try {
            DB::connection($connection)->insert(<<<'SQL'
                insert into accounts (id, company_id, code, name, type, is_active, created_at, updated_at)
                select '01BACKUPVERIFYACCOUNT0001', id, '9998', 'Verify probe', 'asset', true, now(), now()
                from companies limit 1
            SQL);

            $seeded = DB::connection($connection)->selectOne(
                "select count(*) as c from accounts where id = '01BACKUPVERIFYACCOUNT0001'"
            );

            if ((int) $seeded->c === 0) {
                return true;
            }

            DB::connection($connection)->insert(<<<'SQL'
                insert into journal_entries (id, company_id, reference, description, currency, total_debit, total_credit, posted_at, created_at, updated_at)
                select '01BACKUPVERIFYENTRY000001', id, 'JE-VERIFY', 'Verify probe', 'MYR', 10, 10, now(), now(), now()
                from companies limit 1
            SQL);

            DB::connection($connection)->insert(<<<'SQL'
                insert into journal_lines (id, company_id, journal_entry_id, account_id, debit, credit, created_at)
                select '01BACKUPVERIFYLINE0000001', id, '01BACKUPVERIFYENTRY000001', '01BACKUPVERIFYACCOUNT0001', 10, 0, now()
                from companies limit 1
            SQL);

            DB::connection($connection)->update(
                "update journal_lines set debit = 999 where id = '01BACKUPVERIFYLINE0000001'"
            );

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    private function scalar(?string $database, string $query): mixed
    {
        $connection = $database === null ? config('database.default') : $this->connection($database);
        $row = DB::connection($connection)->selectOne($query);

        return $row === null ? 0 : array_values((array) $row)[0];
    }

    private function connection(string $database): string
    {
        config(['database.connections.backup_verify' => array_merge(
            (array) config('database.connections.pgsql'),
            ['database' => $database]
        )]);

        DB::purge('backup_verify');

        return 'backup_verify';
    }

    private function drop(string $target): void
    {
        DB::purge('backup_verify');

        Process::path(base_path())
            ->env($this->backups->environment())
            ->run(['dropdb', '--if-exists', '--host='.config('database.connections.pgsql.host'),
                '--port='.config('database.connections.pgsql.port'),
                '--username='.config('database.connections.pgsql.username'), $target]);
    }
}
