<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\CompanyContext;

/** @return array<int, string> */
function raceReservation(string $companyId, string $stockId, string $quantity, int $processes): array
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
        $command = ['php', 'artisan', 'erp:reserve-stock', $companyId, $stockId, $quantity];
        $process = proc_open($command, $descriptors, $pipes, $root, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the reservation process.');
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
    $this->company = Company::create(['name' => 'Scarcity Co', 'slug' => 'scarcity-'.str()->random(8)]);

    $this->stock = app(CompanyContext::class)->runAs($this->company->getKey(), function (): Stock {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main']);
        $product = Product::create(['sku' => 'LAST', 'name' => 'Last unit']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'LAST-STD',
            'selling_price' => '10.0000',
        ]);

        $service = app(InventoryService::class);
        $stock = $service->lineFor($variant->getKey(), $warehouse);
        $service->receive($stock, '1', StockReason::Opening);

        return $stock->refresh();
    });
});

afterEach(function (): void {
    app(CompanyContext::class)->forget();
    Company::query()->whereKey($this->company->getKey())->forceDelete();
});

it('sells the last unit to exactly one of eight concurrent buyers', function (): void {
    $results = raceReservation($this->company->getKey(), $this->stock->getKey(), '1', 8);

    $reserved = array_filter($results, static fn (string $r): bool => $r === 'RESERVED');
    $refused = array_filter($results, static fn (string $r): bool => $r === 'REFUSED');

    expect($reserved)->toHaveCount(1, 'The same unit was reserved more than once — this is an oversell.')
        ->and($refused)->toHaveCount(7);
});

it('never lets reserved exceed on hand under contention', function (): void {
    raceReservation($this->company->getKey(), $this->stock->getKey(), '1', 8);

    $stock = app(CompanyContext::class)->runAs(
        $this->company->getKey(),
        fn (): Stock => Stock::query()->findOrFail($this->stock->getKey())
    );

    expect(bccomp((string) $stock->reserved, (string) $stock->on_hand, 4))->toBeLessThanOrEqual(0)
        ->and((string) $stock->reserved)->toBe('1.0000');
});

it('holds the movement invariant through a contested reservation round', function (): void {
    raceReservation($this->company->getKey(), $this->stock->getKey(), '1', 8);

    [$sum, $onHand] = app(CompanyContext::class)->runAs($this->company->getKey(), function (): array {
        $stock = Stock::query()->findOrFail($this->stock->getKey());

        return [
            (string) StockMovement::query()->where('stock_id', $stock->getKey())->sum('quantity_delta'),
            (string) $stock->on_hand,
        ];
    });

    expect(bccomp($sum, $onHand, 4))->toBe(0);
});
