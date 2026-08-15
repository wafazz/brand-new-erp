<?php

declare(strict_types=1);

use App\Domain\Purchasing\BillNotPayable;
use App\Domain\Purchasing\PurchasingService;
use App\Domain\Purchasing\ThreeWayMatch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\Warehouse;
use App\Support\CompanyContext;

function purchasingFixture(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $supplier = Supplier::create(['code' => 'SUP1', 'name' => 'Widget Supply']);
        $product = Product::create(['sku' => 'WIDGET', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '100.0000',
            'cost_price' => '60.0000',
        ]);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'reference' => 'PO-0001',
            'status' => 'approved',
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->getKey(),
            'product_variant_id' => $variant->getKey(),
            'sku' => 'WIDGET-STD',
            'product_name' => 'Widget',
            'quantity' => '10',
            'unit_cost' => '60.0000',
            'line_total' => '600.0000',
        ]);

        return compact('company', 'warehouse', 'supplier', 'variant', 'order', 'item');
    });
}

function inPurchasing(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

function billFor(array $f, string $quantity, string $unitCost): SupplierBill
{
    return inPurchasing($f['company'], function () use ($f, $quantity, $unitCost): SupplierBill {
        $bill = SupplierBill::create([
            'purchase_order_id' => $f['order']->getKey(),
            'supplier_id' => $f['supplier']->getKey(),
            'reference' => 'BILL-'.str()->random(5),
            'supplier_invoice_number' => 'SI-'.str()->random(5),
            'billed_at' => now(),
        ]);

        SupplierBillItem::create([
            'supplier_bill_id' => $bill->getKey(),
            'purchase_order_item_id' => $f['item']->getKey(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => bcmul($quantity, $unitCost, 4),
        ]);

        return $bill->refresh();
    });
}

it('receives goods into stock and writes a movement', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '4'],
        ]);
    });

    $stock = inPurchasing($f['company'], fn (): Stock => Stock::query()->firstOrFail());

    expect((string) $stock->on_hand)->toBe('4.0000')
        ->and((string) $f['item']->refresh()->quantity_received)->toBe('4.0000')
        ->and($f['order']->refresh()->status)->toBe('partially_received');
});

it('marks the order received once every line is complete', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '10'],
        ]);
    });

    expect($f['order']->refresh()->status)->toBe('received');
});

it('refuses to receive more than the order still has outstanding', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        expect(fn () => app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '12'],
        ]))->toThrow(InvalidArgumentException::class, 'only 10 is still outstanding');
    });
});

it('keeps the movement invariant after receiving', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        $service = app(PurchasingService::class);
        $service->receiveGoods($f['order'], $f['warehouse'], [['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '4']]);
        $service->receiveGoods($f['order']->refresh(), $f['warehouse'], [['purchase_order_item_id' => $f['item']->refresh()->getKey(), 'quantity' => '3']]);
    });

    [$sum, $onHand] = inPurchasing($f['company'], function (): array {
        $stock = Stock::query()->firstOrFail();

        return [(string) $stock->movements()->sum('quantity_delta'), (string) $stock->on_hand];
    });

    expect(bccomp($sum, $onHand, 4))->toBe(0)->and($onHand)->toBe('7.0000');
});

it('matches a bill that agrees with the order and the goods received', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '10'],
        ]);
    });

    $bill = billFor($f, '10', '60.0000');

    $result = inPurchasing($f['company'], fn () => app(ThreeWayMatch::class)->match($bill));

    expect($result->matched)->toBeTrue()->and($result->reason())->toBeNull();
});

it('blocks a bill for more than was received', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '4'],
        ]);
    });

    $bill = billFor($f, '10', '60.0000');

    $result = inPurchasing($f['company'], fn () => app(ThreeWayMatch::class)->match($bill));

    expect($result->matched)->toBeFalse()
        ->and($result->reason())->toContain('billed 10 but only 4 was received');
});

it('blocks a bill priced differently from the order', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '10'],
        ]);
    });

    $bill = billFor($f, '10', '75.0000');

    $result = inPurchasing($f['company'], fn () => app(ThreeWayMatch::class)->match($bill));

    expect($result->matched)->toBeFalse()
        ->and($result->reason())->toContain('ordered at MYR 60.00 but billed at MYR 75.00');
});

it('refuses to pay a bill that does not match', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '2'],
        ]);
    });

    $bill = billFor($f, '10', '60.0000');

    inPurchasing($f['company'], function () use ($bill): void {
        expect(fn () => app(PurchasingService::class)->assertBillPayable($bill))
            ->toThrow(BillNotPayable::class, 'This bill does not match the order and the goods received');
    });
});

it('reports every discrepancy rather than stopping at the first', function (): void {
    $f = purchasingFixture();

    inPurchasing($f['company'], function () use ($f): void {
        app(PurchasingService::class)->receiveGoods($f['order'], $f['warehouse'], [
            ['purchase_order_item_id' => $f['item']->getKey(), 'quantity' => '2'],
        ]);
    });

    $bill = billFor($f, '10', '75.0000');

    $result = inPurchasing($f['company'], fn () => app(ThreeWayMatch::class)->match($bill));

    expect($result->discrepancies)->toHaveCount(2)
        ->and(collect($result->toArray())->pluck('kind')->all())
        ->toBe(['over_billed_quantity', 'price_variance']);
});

it('recalculates a bill total from its lines', function (): void {
    $f = purchasingFixture();
    $bill = billFor($f, '10', '60.0000');

    $bill = inPurchasing($f['company'], fn () => app(PurchasingService::class)->recalculateBill($bill));

    expect((string) $bill->subtotal)->toBe('600.0000')->and((string) $bill->total)->toBe('600.0000');
});
