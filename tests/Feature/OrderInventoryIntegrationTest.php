<?php

declare(strict_types=1);

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Support\CompanyContext;

function integrationFixture(string $opening = '10', ?string $taxPercent = null, bool $inclusive = false): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $opening, $taxPercent, $inclusive): array {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);

        $tax = $taxPercent === null ? null : TaxRate::create([
            'code' => 'SST',
            'name' => 'Sales tax',
            'rate_percent' => $taxPercent,
            'is_inclusive' => $inclusive,
        ]);

        $product = Product::create([
            'sku' => 'WIDGET',
            'name' => 'Widget',
            'tax_rate_id' => $tax?->getKey(),
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '100.0000',
            'cost_price' => '60.0000',
        ]);

        $inventory = app(InventoryService::class);
        $stock = $inventory->lineFor($variant->getKey(), $warehouse);
        $inventory->receive($stock, $opening, StockReason::Opening);

        $order = app(OrderService::class)->create([
            'customer_name' => 'Walk-in',
            'is_cod' => true,
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '3']],
        ]);

        return compact('company', 'warehouse', 'variant', 'stock', 'order');
    });
}

function step(Company $company, Order $order, FulfilmentStatus ...$steps): Order
{
    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($order, $steps): Order {
        $machine = app(OrderStateMachine::class);

        foreach ($steps as $s) {
            $order = $machine->transition($order, $s);
        }

        return $order;
    });
}

it('reserves stock when an order is allocated', function (): void {
    $f = integrationFixture('10');

    step($f['company'], $f['order'], FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated);

    $stock = $f['stock']->refresh();

    expect((string) $stock->on_hand)->toBe('10.0000')
        ->and((string) $stock->reserved)->toBe('3.0000');
});

it('commits the reservation when the order ships', function (): void {
    $f = integrationFixture('10');

    step($f['company'], $f['order'],
        FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated,
        FulfilmentStatus::Picked, FulfilmentStatus::Packed, FulfilmentStatus::Shipped);

    $stock = $f['stock']->refresh();

    expect((string) $stock->on_hand)->toBe('7.0000')
        ->and((string) $stock->reserved)->toBe('0.0000');
});

it('releases the reservation when an allocated order is cancelled', function (): void {
    $f = integrationFixture('10');

    $order = step($f['company'], $f['order'], FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated);

    app(CompanyContext::class)->runAs($f['company']->getKey(), function () use ($order): void {
        app(OrderStateMachine::class)->transition($order, ExceptionStatus::Cancelled);
    });

    $stock = $f['stock']->refresh();

    expect((string) $stock->on_hand)->toBe('10.0000')
        ->and((string) $stock->reserved)->toBe('0.0000');
});

it('holds the movement invariant across the whole order lifecycle', function (): void {
    $f = integrationFixture('10');

    step($f['company'], $f['order'],
        FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated,
        FulfilmentStatus::Picked, FulfilmentStatus::Packed, FulfilmentStatus::Shipped);

    [$sum, $onHand] = app(CompanyContext::class)->runAs($f['company']->getKey(), function () use ($f): array {
        $stock = Stock::query()->findOrFail($f['stock']->getKey());

        return [(string) $stock->movements()->sum('quantity_delta'), (string) $stock->on_hand];
    });

    expect(bccomp($sum, $onHand, 4))->toBe(0);
});

it('refuses to allocate more than is in stock', function (): void {
    $f = integrationFixture('2');

    app(CompanyContext::class)->runAs($f['company']->getKey(), function () use ($f): void {
        $machine = app(OrderStateMachine::class);
        $order = $machine->transition($f['order'], FulfilmentStatus::Pending);
        $order = $machine->transition($order, FulfilmentStatus::Approved);

        expect(fn () => $machine->transition($order, FulfilmentStatus::Allocated))
            ->toThrow(InsufficientStock::class, 'Only 2 of this item is available and 3 was requested.');
    });
});

it('records one reservation per order line', function (): void {
    $f = integrationFixture('10');

    step($f['company'], $f['order'], FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated);

    $reservations = app(CompanyContext::class)->runAs(
        $f['company']->getKey(),
        fn () => StockReservation::query()->where('order_id', $f['order']->getKey())->get()
    );

    expect($reservations)->toHaveCount(1)
        ->and($reservations[0]->status)->toBe('held')
        ->and((string) $reservations[0]->quantity)->toBe('3.0000')
        ->and($reservations[0]->expires_at)->toBeNull();
});

it('applies an exclusive tax rate to the line', function (): void {
    $f = integrationFixture('10', '6');

    $item = app(CompanyContext::class)->runAs($f['company']->getKey(), fn () => $f['order']->items()->firstOrFail());

    expect((string) $item->line_total)->toBe('300.0000')
        ->and((string) $item->tax_amount)->toBe('18.0000')
        ->and((string) $f['order']->refresh()->total)->toBe('318.0000');
});

it('extracts an inclusive tax rate from the line', function (): void {
    $f = integrationFixture('10', '6', inclusive: true);

    $item = app(CompanyContext::class)->runAs($f['company']->getKey(), fn () => $f['order']->items()->firstOrFail());

    expect((string) $item->line_total)->toBe('300.0000')
        ->and((string) $item->tax_amount)->toBe('16.9812');
});

it('charges no tax when the product has no rate', function (): void {
    $f = integrationFixture('10');

    $item = app(CompanyContext::class)->runAs($f['company']->getKey(), fn () => $f['order']->items()->firstOrFail());

    expect((string) $item->tax_amount)->toBe('0.0000');
});
