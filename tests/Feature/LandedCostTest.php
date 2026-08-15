<?php

declare(strict_types=1);

use App\Domain\Orders\OrderService;
use App\Domain\Purchasing\CostingService;
use App\Domain\Purchasing\PurchasingService;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\CompanyContext;

function landedWorld(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $supplier = Supplier::create(['code' => 'S1', 'name' => 'Supply Co']);
        $product = Product::create(['sku' => 'W', 'name' => 'Widget']);

        $cheap = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'W-CHEAP',
            'selling_price' => '100.0000',
            'cost_price' => '40.0000',
            'weight_grams' => 100,
        ]);

        $pricey = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'W-PRICEY',
            'selling_price' => '300.0000',
            'cost_price' => '160.0000',
            'weight_grams' => 100,
        ]);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'reference' => 'PO-1',
            'status' => 'approved',
        ]);

        $cheapLine = PurchaseOrderItem::create([
            'purchase_order_id' => $order->getKey(),
            'product_variant_id' => $cheap->getKey(),
            'sku' => 'W-CHEAP', 'product_name' => 'Widget cheap',
            'quantity' => '10', 'unit_cost' => '40.0000', 'line_total' => '400.0000',
        ]);

        $priceyLine = PurchaseOrderItem::create([
            'purchase_order_id' => $order->getKey(),
            'product_variant_id' => $pricey->getKey(),
            'sku' => 'W-PRICEY', 'product_name' => 'Widget pricey',
            'quantity' => '10', 'unit_cost' => '160.0000', 'line_total' => '1600.0000',
        ]);

        return compact('company', 'warehouse', 'order', 'cheap', 'pricey', 'cheapLine', 'priceyLine');
    });
}

function inLanded(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

function receiveAll(array $w): void
{
    inLanded($w['company'], function () use ($w): void {
        app(PurchasingService::class)->receiveGoods($w['order'], $w['warehouse'], [
            ['purchase_order_item_id' => $w['cheapLine']->getKey(), 'quantity' => '10'],
            ['purchase_order_item_id' => $w['priceyLine']->getKey(), 'quantity' => '10'],
        ]);
    });
}

it('sets the average cost from the purchase price when nothing else is known', function (): void {
    $w = landedWorld();
    receiveAll($w);

    expect((string) $w['cheap']->refresh()->average_cost)->toBe('40.0000')
        ->and((string) $w['pricey']->refresh()->average_cost)->toBe('160.0000');
});

it('apportions freight by line value, not evenly', function (): void {
    $w = landedWorld();
    receiveAll($w);

    inLanded($w['company'], function (): void {
        $receipt = GoodsReceipt::query()->firstOrFail();
        app(CostingService::class)->addLandedCost($receipt, 'freight', '200.0000', 'by_value');
    });

    expect((string) $w['cheap']->refresh()->average_cost)->toBe('44.0000')
        ->and((string) $w['pricey']->refresh()->average_cost)->toBe('176.0000');
});

it('apportions duty by quantity when the plan says so', function (): void {
    $w = landedWorld();
    receiveAll($w);

    inLanded($w['company'], function (): void {
        $receipt = GoodsReceipt::query()->firstOrFail();
        app(CostingService::class)->addLandedCost($receipt, 'duty', '200.0000', 'by_quantity');
    });

    expect((string) $w['cheap']->refresh()->average_cost)->toBe('50.0000')
        ->and((string) $w['pricey']->refresh()->average_cost)->toBe('170.0000');
});

it('explains how a landed unit cost was reached', function (): void {
    $w = landedWorld();
    receiveAll($w);

    $basis = inLanded($w['company'], function () use ($w): array {
        $receipt = GoodsReceipt::query()->firstOrFail();
        app(CostingService::class)->addLandedCost($receipt, 'freight', '200.0000', 'by_value');

        return GoodsReceiptItem::query()
            ->where('product_variant_id', $w['cheap']->getKey())
            ->firstOrFail()
            ->landed_cost_basis;
    });

    expect($basis['purchase_unit_cost'])->toBe('40.0000')
        ->and($basis['landed_unit_cost'])->toBe('44.0000')
        ->and($basis['components'][0]['kind'])->toBe('freight')
        ->and($basis['components'][0]['share'])->toBe('40.0000')
        ->and($basis['explanation'])->toContain('Purchase MYR 40.00 plus freight 4.0000 per unit = MYR 44.00');
});

it('does not double count when costing is applied again', function (): void {
    $w = landedWorld();
    receiveAll($w);

    inLanded($w['company'], function (): void {
        $receipt = GoodsReceipt::query()->firstOrFail();
        $costing = app(CostingService::class);

        $costing->addLandedCost($receipt, 'freight', '200.0000', 'by_value');
        $costing->applyCosting($receipt);
        $costing->applyCosting($receipt);
    });

    expect((string) $w['cheap']->refresh()->average_cost)->toBe('44.0000');
});

it('weights the average across two receipts at different costs', function (): void {
    $w = landedWorld();
    receiveAll($w);

    inLanded($w['company'], function () use ($w): void {
        $second = PurchaseOrder::create([
            'supplier_id' => $w['order']->supplier_id,
            'warehouse_id' => $w['warehouse']->getKey(),
            'reference' => 'PO-2',
            'status' => 'approved',
        ]);

        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $second->getKey(),
            'product_variant_id' => $w['cheap']->getKey(),
            'sku' => 'W-CHEAP', 'product_name' => 'Widget cheap',
            'quantity' => '10', 'unit_cost' => '60.0000', 'line_total' => '600.0000',
        ]);

        app(PurchasingService::class)->receiveGoods($second, $w['warehouse'], [
            ['purchase_order_item_id' => $line->getKey(), 'quantity' => '10'],
        ]);
    });

    expect((string) $w['cheap']->refresh()->average_cost)->toBe('50.0000')
        ->and((string) $w['cheap']->refresh()->cost_quantity)->toBe('20.0000');
});

it('makes an order line use the real cost rather than the typed one', function (): void {
    $w = landedWorld();
    receiveAll($w);

    inLanded($w['company'], function () use ($w): void {
        $receipt = GoodsReceipt::query()->firstOrFail();
        app(CostingService::class)->addLandedCost($receipt, 'freight', '200.0000', 'by_value');

        $order = app(OrderService::class)->create([
            'customer_name' => 'Walk-in',
            'lines' => [['variant_id' => $w['cheap']->getKey(), 'quantity' => '2']],
        ]);

        $item = $order->items()->firstOrFail();

        expect((string) $item->unit_cost)->toBe('44.0000')
            ->and($item->unit_cost_source)->toBe('average');
    });
});

it('falls back to the typed cost when nothing has been received', function (): void {
    $w = landedWorld();

    inLanded($w['company'], function () use ($w): void {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Walk-in',
            'lines' => [['variant_id' => $w['cheap']->getKey(), 'quantity' => '1']],
        ]);

        $item = $order->items()->firstOrFail();

        expect((string) $item->unit_cost)->toBe('40.0000')
            ->and($item->unit_cost_source)->toBe('standard');
    });
});

it('changes the commission a margin plan computes', function (): void {
    $w = landedWorld();
    receiveAll($w);

    $margins = inLanded($w['company'], function () use ($w): array {
        $receipt = GoodsReceipt::query()->firstOrFail();

        $before = app(OrderService::class)->create([
            'customer_name' => 'Before freight',
            'lines' => [['variant_id' => $w['cheap']->getKey(), 'quantity' => '10']],
        ]);

        app(CostingService::class)->addLandedCost($receipt, 'freight', '200.0000', 'by_value');

        $after = app(OrderService::class)->create([
            'customer_name' => 'After freight',
            'lines' => [['variant_id' => $w['cheap']->getKey(), 'quantity' => '10']],
        ]);

        return [
            'before' => (string) $before->items()->firstOrFail()->unit_cost,
            'after' => (string) $after->items()->firstOrFail()->unit_cost,
        ];
    });

    expect($margins['before'])->toBe('40.0000')
        ->and($margins['after'])->toBe('44.0000');
});
