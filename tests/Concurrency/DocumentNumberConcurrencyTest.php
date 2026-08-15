<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\DocumentSequence;
use App\Support\CompanyContext;

/**
 * @param  array<int, array{0: resource, 1: array<int, resource>}>  $running
 * @return array<int, string>
 */
function drain(array $running): array
{
    $lines = [];

    foreach ($running as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);

        if ($status !== 0) {
            throw new RuntimeException("Allocator exited {$status}: ".$err);
        }

        foreach (preg_split('/\R/', trim((string) $out)) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }
    }

    return $lines;
}

/** @return array<int, string> */
function allocateInParallel(string $companyId, string $key, int $processes, int $perProcess): array
{
    $root = dirname(__DIR__, 2);

    $connection = config('database.connections.pgsql');

    $env = [
        'PATH' => getenv('PATH'),
        'HOME' => getenv('HOME'),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $running = [];

    for ($i = 0; $i < $processes; $i++) {
        $command = ['php', 'artisan', 'erp:allocate-numbers', $companyId, $key, (string) $perProcess, '--prefix=INV'];
        $process = proc_open($command, $descriptors, $pipes, $root, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the allocator process.');
        }

        $running[] = [$process, $pipes];
    }

    return drain($running);
}

beforeEach(function (): void {
    $this->company = Company::create([
        'name' => 'Concurrency Co',
        'slug' => 'concurrency-'.str()->random(8),
    ]);
});

afterEach(function (): void {
    app(CompanyContext::class)->forget();
    Company::query()->whereKey($this->company->getKey())->forceDelete();
});

it('allocates a contiguous unique sequence under eight concurrent processes', function (): void {
    $processes = 8;
    $perProcess = 10;
    $expected = $processes * $perProcess;

    $numbers = allocateInParallel($this->company->getKey(), 'invoice', $processes, $perProcess);

    expect($numbers)->toHaveCount($expected);

    $unique = array_unique($numbers);

    expect($unique)->toHaveCount($expected, 'The allocator issued a duplicate document number under concurrency.');

    $suffixes = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1), $numbers);
    sort($suffixes);

    expect($suffixes)->toBe(range(1, $expected));
});

it('formats the number with its prefix and padding', function (): void {
    $numbers = allocateInParallel($this->company->getKey(), 'invoice', 1, 1);

    expect($numbers[0])->toBe('INV-00001');
});

it('leaves the sequence pointing at the next unused number', function (): void {
    allocateInParallel($this->company->getKey(), 'invoice', 4, 5);

    $sequence = app(CompanyContext::class)->runAs(
        $this->company->getKey(),
        fn (): DocumentSequence => DocumentSequence::query()->where('key', 'invoice')->firstOrFail()
    );

    expect((int) $sequence->next_number)->toBe(21);
});

it('keeps separate keys on separate counters', function (): void {
    $invoices = allocateInParallel($this->company->getKey(), 'invoice', 2, 3);
    $orders = allocateInParallel($this->company->getKey(), 'order', 2, 3);

    $invoiceSuffixes = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1), $invoices);
    $orderSuffixes = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1), $orders);

    sort($invoiceSuffixes);
    sort($orderSuffixes);

    expect($invoiceSuffixes)->toBe(range(1, 6))
        ->and($orderSuffixes)->toBe(range(1, 6));
});
