<?php

declare(strict_types=1);

use App\Domain\Backup\BackupReport;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\RestoreVerifier;
use App\Domain\Backup\VerificationReport;
use Illuminate\Support\Facades\Schedule;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/erp-backup-'.str()->random(8);
    config(['backup.directory' => $this->directory]);
});

afterEach(function (): void {
    foreach (glob($this->directory.'/*') ?: [] as $file) {
        unlink($file);
    }

    if (is_dir($this->directory)) {
        rmdir($this->directory);
    }
});

it('schedules a nightly backup and a weekly restore rehearsal', function (): void {
    $commands = collect(Schedule::events())->map(fn ($e): string => (string) $e->command);

    expect($commands->filter(fn (string $c): bool => str_contains($c, 'erp:backup')))->toHaveCount(1)
        ->and($commands->filter(fn (string $c): bool => str_contains($c, 'erp:verify-backup')))->toHaveCount(1);
});

it('writes a dump that is large enough to be a backup', function (): void {
    $report = app(BackupService::class)->run();

    expect(is_file($report->file))->toBeTrue()
        ->and($report->bytes)->toBeGreaterThan(1024)
        ->and($report->explain())->toContain('offsite: not configured');
});

it('finds the most recent dump', function (): void {
    $service = app(BackupService::class);
    $first = $service->run();
    $second = $service->run();

    $latest = $service->latest();

    expect([$first->file, $second->file])->toContain($latest)
        ->and($first->file)->not->toBe($second->file)
        ->and(filemtime($latest))->toBe(max(filemtime($first->file), filemtime($second->file)));
});

it('prunes dumps older than the retention window', function (): void {
    config(['backup.keep_days' => 1]);

    $service = app(BackupService::class);
    $old = $service->run()->file;
    touch($old, now()->subDays(5)->getTimestamp());

    $report = $service->run();

    expect($report->pruned)->toBe(1)
        ->and(is_file($old))->toBeFalse();
});

it('refuses to enable offsite backup without a command', function (): void {
    config(['backup.offsite.enabled' => true, 'backup.offsite.command' => '']);

    expect(fn () => app(BackupService::class)->run())
        ->toThrow(RuntimeException::class, 'A backup that lives only on the machine it protects is not a backup');
});

it('restores its own dump and proves the copy still matches', function (): void {
    $report = app(BackupService::class)->run();
    $verification = app(RestoreVerifier::class)->verify($report->file);

    expect($verification->passed())->toBeTrue($verification->explain())
        ->and($verification->appendOnlyHeld)->toBeTrue()
        ->and($verification->counts['tables']['original'])->toBe($verification->counts['tables']['restored']);
});

it('refuses to verify when there is no dump at all', function (): void {
    expect(fn () => app(RestoreVerifier::class)->verify())
        ->toThrow(RuntimeException::class, 'A backup nobody has restored is a hope, not a backup');
});

it('fails verification when the restored copy is missing objects', function (): void {
    $report = new VerificationReport('drifted.dump', [
        'tables' => ['original' => 107, 'restored' => 106],
        'triggers' => ['original' => 11, 'restored' => 11],
    ], true);

    expect($report->passed())->toBeFalse()
        ->and($report->explain())->toContain('tables 107/106 MISMATCH');
});

it('fails verification when append-only protection did not survive', function (): void {
    $report = new VerificationReport('unsafe.dump', [
        'tables' => ['original' => 107, 'restored' => 107],
    ], false);

    expect($report->passed())->toBeFalse()
        ->and($report->explain())->toContain('append-only LOST');
});

it('describes what a backup run did', function (): void {
    $report = new BackupReport('/tmp/x.dump', 409600, 2, 'rsync …');

    expect($report->explain())->toBe('Wrote /tmp/x.dump (400.0 KB), pruned 2 old dump(s), offsite: rsync ….');
});
