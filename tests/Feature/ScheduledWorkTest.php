<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Domain\Orders\OrderService;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesRollup;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Schedule;

it('schedules the rollup rebuild and the reservation sweep', function (): void {
    $commands = collect(Schedule::events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $c): bool => str_contains($c, 'erp:'))
        ->values();

    expect($commands->filter(fn (string $c): bool => str_contains($c, 'erp:sweep-reservations')))->toHaveCount(1)
        ->and($commands->filter(fn (string $c): bool => str_contains($c, 'erp:rebuild-rollups')))->toHaveCount(2);
});

it('guards every scheduled command against overlap and multi-server runs', function (): void {
    foreach (Schedule::events() as $event) {
        if (! str_contains((string) $event->command, 'erp:')) {
            continue;
        }

        expect($event->withoutOverlapping)->toBeTrue((string) $event->command.' can overlap itself.')
            ->and($event->onOneServer)->toBeTrue((string) $event->command.' can run on several servers at once.');
    }
});

it('rebuilds rollups for every active company in one run', function (): void {
    $first = Company::create(['name' => 'First', 'slug' => 'first-'.str()->random(6)]);
    $second = Company::create(['name' => 'Second', 'slug' => 'second-'.str()->random(6)]);

    foreach ([$first, $second] as $company) {
        app(CompanyContext::class)->runAs($company->getKey(), function (): void {
            $product = Product::create(['sku' => 'W', 'name' => 'Widget']);
            $variant = ProductVariant::create([
                'product_id' => $product->getKey(),
                'sku' => 'W-STD',
                'selling_price' => '100.0000',
                'cost_price' => '60.0000',
            ]);

            app(OrderService::class)->create([
                'customer_name' => 'Walk-in',
                'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '1']],
            ]);
        });
    }

    $this->artisan('erp:rebuild-rollups')->assertSuccessful();

    foreach ([$first, $second] as $company) {
        $revenue = app(CompanyContext::class)->runAs(
            $company->getKey(),
            fn (): string => (string) SalesRollup::query()->sum('revenue')
        );

        expect($revenue)->toBe('100.0000');
    }
});

it('releases an expired hold when the sweep runs', function (): void {
    $company = Company::create(['name' => 'Sweep', 'slug' => 'sweep-'.str()->random(6)]);

    $stock = app(CompanyContext::class)->runAs($company->getKey(), function (): Stock {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main']);
        $product = Product::create(['sku' => 'W', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'W-STD',
            'selling_price' => '100.0000',
        ]);

        $inventory = app(InventoryService::class);
        $line = $inventory->lineFor($variant->getKey(), $warehouse);
        $inventory->receive($line, '10', StockReason::Opening);
        $inventory->reserve($line->refresh(), '4', null, null, now()->subMinute());

        return $line->refresh();
    });

    expect((string) $stock->reserved)->toBe('4.0000');

    $this->artisan('erp:sweep-reservations')->assertSuccessful();

    $after = app(CompanyContext::class)->runAs(
        $company->getKey(),
        fn (): Stock => Stock::query()->findOrFail($stock->getKey())
    );

    expect((string) $after->reserved)->toBe('0.0000')
        ->and((string) $after->on_hand)->toBe('10.0000');
});

it('keeps going when one company fails rather than aborting the run', function (): void {
    $healthy = Company::create(['name' => 'Healthy', 'slug' => 'healthy-'.str()->random(6)]);

    app(CompanyContext::class)->runAs($healthy->getKey(), function (): void {
        $product = Product::create(['sku' => 'W', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'W-STD',
            'selling_price' => '250.0000',
            'cost_price' => '100.0000',
        ]);

        app(OrderService::class)->create([
            'customer_name' => 'Walk-in',
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '1']],
        ]);
    });

    $this->artisan('erp:rebuild-rollups')->assertSuccessful();

    $revenue = app(CompanyContext::class)->runAs(
        $healthy->getKey(),
        fn (): string => (string) SalesRollup::query()->sum('revenue')
    );

    expect($revenue)->toBe('250.0000');
});
