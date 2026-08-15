<?php

declare(strict_types=1);

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\CompanyContext;

function stockFixture(string $opening = '10'): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $opening): array {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $product = Product::create(['sku' => 'WIDGET', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '100.0000',
            'cost_price' => '60.0000',
        ]);

        $service = app(InventoryService::class);
        $stock = $service->lineFor($variant->getKey(), $warehouse);
        $service->receive($stock, $opening, StockReason::Opening);

        return ['company' => $company, 'warehouse' => $warehouse, 'variant' => $variant, 'stock' => $stock->refresh()];
    });
}

function withStock(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

it('derives available from on hand minus reserved', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $service = app(InventoryService::class);
        expect($service->available($f['stock']))->toBe('10.0000');

        $service->reserve($f['stock'], '3');

        expect($service->available($f['stock']->refresh()))->toBe('7.0000');
    });
});

it('refuses a reservation larger than what is available and says the numbers', function (): void {
    $f = stockFixture('5');

    withStock($f['company'], function () use ($f): void {
        expect(fn () => app(InventoryService::class)->reserve($f['stock'], '9'))
            ->toThrow(InsufficientStock::class, 'Only 5 of this item is available and 9 was requested.');
    });
});

it('never changes on hand when reserving', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        app(InventoryService::class)->reserve($f['stock'], '4');
    });

    expect((string) $f['stock']->refresh()->on_hand)->toBe('10.0000')
        ->and((string) $f['stock']->refresh()->reserved)->toBe('4.0000');
});

it('decrements on hand only when a reservation is committed', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $service = app(InventoryService::class);
        $reservation = $service->reserve($f['stock'], '4');
        $service->commit($reservation);
    });

    $stock = $f['stock']->refresh();

    expect((string) $stock->on_hand)->toBe('6.0000')
        ->and((string) $stock->reserved)->toBe('0.0000');
});

it('frees the reservation on release without touching on hand', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $service = app(InventoryService::class);
        $reservation = $service->reserve($f['stock'], '4');
        expect($service->release($reservation))->toBeTrue();
    });

    $stock = $f['stock']->refresh();

    expect((string) $stock->on_hand)->toBe('10.0000')
        ->and((string) $stock->reserved)->toBe('0.0000');
});

it('discharges a reservation exactly once', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $service = app(InventoryService::class);
        $reservation = $service->reserve($f['stock'], '4');

        $service->commit($reservation);

        expect($service->commit($reservation->refresh()))->toBeNull()
            ->and($service->release($reservation->refresh()))->toBeFalse();
    });

    expect((string) $f['stock']->refresh()->on_hand)->toBe('6.0000');
});

it('keeps the sum of movements equal to on hand', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $service = app(InventoryService::class);
        $service->receive($f['stock'], '25');
        $service->adjust($f['stock'], '-3', StockReason::Damaged);
        $committed = $service->reserve($f['stock'], '5');
        $service->commit($committed);
        $service->adjust($f['stock'], '2', StockReason::StockTake);
    });

    $stock = $f['stock']->refresh();
    $sum = withStock($f['company'], fn (): string => (string) StockMovement::query()
        ->where('stock_id', $stock->getKey())
        ->sum('quantity_delta'));

    expect(bccomp($sum, (string) $stock->on_hand, 4))->toBe(0)
        ->and((string) $stock->on_hand)->toBe('29.0000');
});

it('records a balance_after that matches the running total', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $service = app(InventoryService::class);
        $service->receive($f['stock'], '5');
        $service->adjust($f['stock'], '-2', StockReason::Damaged);
    });

    $movements = withStock($f['company'], fn () => StockMovement::query()
        ->where('stock_id', $f['stock']->getKey())
        ->orderBy('created_at')
        ->get());

    $running = '0';

    foreach ($movements as $movement) {
        $running = bcadd($running, (string) $movement->quantity_delta, 4);
        expect(bccomp($running, (string) $movement->balance_after, 4))->toBe(0);
    }
});

it('lets on hand go negative rather than refusing to represent a miscount', function (): void {
    $f = stockFixture('2');

    withStock($f['company'], function () use ($f): void {
        app(InventoryService::class)->adjust($f['stock'], '-5', StockReason::StockTake);
    });

    expect((string) $f['stock']->refresh()->on_hand)->toBe('-3.0000');
});

it('keeps stock movements append-only', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $movement = StockMovement::query()->where('stock_id', $f['stock']->getKey())->firstOrFail();

        expect(fn () => $movement->update(['quantity_delta' => '999']))->toThrow(RuntimeException::class);
    });
});

it('sweeps only expired speculative holds', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $service = app(InventoryService::class);
        $service->reserve($f['stock'], '2', null, null, now()->subMinute());
        $service->reserve($f['stock'], '3', null, null, now()->addHour());

        expect($service->sweepExpired())->toBe(1);
    });

    expect((string) $f['stock']->refresh()->reserved)->toBe('3.0000');
});

it('creates one stock line per warehouse and variant', function (): void {
    $f = stockFixture('10');

    withStock($f['company'], function () use ($f): void {
        $again = app(InventoryService::class)->lineFor($f['variant']->getKey(), $f['warehouse']);

        expect($again->getKey())->toBe($f['stock']->getKey())
            ->and(Stock::query()->count())->toBe(1);
    });
});
