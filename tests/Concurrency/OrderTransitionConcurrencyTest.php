<?php

declare(strict_types=1);

use App\Enums\FulfilmentStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\CompanyContext;

/** @return array<int, string> */
function raceTransition(string $companyId, string $orderId, string $axis, string $target, int $processes): array
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
        $command = ['php', 'artisan', 'erp:transition-order', $companyId, $orderId, $axis, $target];
        $process = proc_open($command, $descriptors, $pipes, $root, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the transition process.');
        }

        $running[] = [$process, $pipes];
    }

    $results = [];

    foreach ($running as [$process, $pipes]) {
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $results[] = $out !== '' ? $out : $err;
    }

    return $results;
}

beforeEach(function (): void {
    $this->company = Company::create([
        'name' => 'Race Co',
        'slug' => 'race-'.str()->random(8),
    ]);

    $this->order = app(CompanyContext::class)->runAs($this->company->getKey(), fn (): Order => Order::create([
        'order_number' => 'SO-RACE-'.str()->random(5),
        'customer_name' => 'Racer',
    ]));
});

afterEach(function (): void {
    app(CompanyContext::class)->forget();
    Company::query()->whereKey($this->company->getKey())->forceDelete();
});

it('applies a contested transition exactly once', function (): void {
    $results = raceTransition($this->company->getKey(), $this->order->getKey(), 'fulfilment', 'pending', 6);

    $applied = array_filter($results, static fn (string $r): bool => $r === 'APPLIED');
    $refused = array_filter($results, static fn (string $r): bool => str_starts_with($r, 'REFUSED'));

    expect($applied)->toHaveCount(1, 'The same transition was applied more than once under concurrency.')
        ->and($refused)->toHaveCount(5);
});

it('writes exactly one event for a contested transition', function (): void {
    raceTransition($this->company->getKey(), $this->order->getKey(), 'fulfilment', 'pending', 6);

    $events = app(CompanyContext::class)->runAs(
        $this->company->getKey(),
        fn (): int => OrderEvent::query()
            ->where('order_id', $this->order->getKey())
            ->where('event', 'fulfilment.status_changed')
            ->count()
    );

    expect($events)->toBe(1);
});

it('leaves the order on the expected status after the race', function (): void {
    raceTransition($this->company->getKey(), $this->order->getKey(), 'fulfilment', 'pending', 6);

    $order = app(CompanyContext::class)->runAs(
        $this->company->getKey(),
        fn (): Order => Order::query()->findOrFail($this->order->getKey())
    );

    expect($order->fulfilment_status)->toBe(FulfilmentStatus::Pending);
});
