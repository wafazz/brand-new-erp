<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptCost;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Access\RoleProvisioner;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

/** @return array<string, mixed> */
function buyingFixture(): array
{
    $f = routeFixture();

    $extra = test()->withCompany($f['company'], function (): array {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        $supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Widget Supply']);
        $product = Product::create(['sku' => 'WID', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WID-STD',
            'name' => 'Standard',
            'cost_price' => '60',
            'selling_price' => '100',
            'is_default' => true,
        ]);

        return compact('warehouse', 'supplier', 'product', 'variant');
    });

    return [...$f, ...$extra];
}

function buyer(array $f, string $email = 'buyer@acme.test'): User
{
    return person($f['company'], CompanyRole::Purchaser, $email, $f['branch']);
}

/** @return array{0: PurchaseOrder, 1: PurchaseOrderItem} */
function approvedOrder(array $f, string $quantity = '10', string $unitCost = '60'): array
{
    return test()->withCompany($f['company'], function () use ($f, $quantity, $unitCost): array {
        $order = PurchaseOrder::create([
            'supplier_id' => $f['supplier']->getKey(),
            'warehouse_id' => $f['warehouse']->getKey(),
            'branch_id' => $f['branch']->getKey(),
            'reference' => 'PO-'.str()->random(5),
            'status' => 'approved',
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->getKey(),
            'product_variant_id' => $f['variant']->getKey(),
            'sku' => 'WID-STD',
            'product_name' => 'Widget',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => bcmul($quantity, $unitCost, 4),
        ]);

        $order->forceFill([
            'subtotal' => bcmul($quantity, $unitCost, 4),
            'total' => bcmul($quantity, $unitCost, 4),
        ])->save();

        return [$order->refresh(), $item->refresh()];
    });
}

it('refuses every purchasing screen to a role without purchasing.view', function (): void {
    $f = buyingFixture();

    $this->actingAs($f['alice'])->get('/dashboard')->assertOk();

    foreach (['/purchase-requests', '/purchase-orders', '/supplier-bills'] as $path) {
        $this->actingAs($f['alice'])->get($path)->assertForbidden();
    }
});

it('shows a purchaser the purchase request list', function (): void {
    $f = buyingFixture();
    $purchaser = buyer($f);

    $this->withCompany($f['company'], function () use ($f, $purchaser): void {
        PurchaseRequest::create([
            'branch_id' => $f['branch']->getKey(),
            'requested_by' => $purchaser->getKey(),
            'reference' => 'PR-0001',
            'status' => 'draft',
        ]);
    });

    $this->actingAs($purchaser)
        ->get('/purchase-requests')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Purchasing/Requests/Index')
            ->has('requests.data', 1)
            ->where('requests.data.0.reference', 'PR-0001'));
});

it('shows a branch-scoped buyer only the requests raised in their branch', function (): void {
    $f = buyingFixture();

    $northBranch = $this->withCompany($f['company'], fn (): Branch => Branch::create(['code' => 'NTH', 'name' => 'North']));

    $hqBuyer = person($f['company'], CompanyRole::Purchaser, 'hqbuyer@acme.test', $f['branch']);
    $northBuyer = person($f['company'], CompanyRole::Purchaser, 'northbuyer@acme.test', $northBranch);

    grant($f['company'], CompanyRole::Purchaser, 'purchasing.view', DataScope::Branch);

    $this->withCompany($f['company'], function () use ($f, $northBranch, $hqBuyer): void {
        PurchaseRequest::create(['branch_id' => $f['branch']->getKey(), 'requested_by' => $hqBuyer->getKey(), 'reference' => 'PR-HQ']);
        PurchaseRequest::create(['branch_id' => $northBranch->getKey(), 'requested_by' => $hqBuyer->getKey(), 'reference' => 'PR-NORTH']);
    });

    $response = $this->actingAs($northBuyer)->get('/purchase-requests');

    $response->assertInertia(fn (AssertableInertia $page) => $page->has('requests.data', 1)
        ->where('requests.data.0.reference', 'PR-NORTH'));

    expect($response->getContent())->not->toContain('PR-HQ');
});

it('raises a purchase request with its lines and allocates a reference', function (): void {
    $f = buyingFixture();
    $purchaser = buyer($f);

    $this->actingAs($purchaser)->post('/purchase-requests', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['product_variant_id' => $f['variant']->getKey(), 'quantity' => '5', 'note' => 'Running low']],
    ])->assertRedirect();

    $this->withCompany($f['company'], function () use ($purchaser): void {
        $record = PurchaseRequest::query()->firstOrFail();

        expect($record->reference)->toStartWith('PR-')
            ->and($record->status)->toBe('draft')
            ->and($record->requested_by)->toBe($purchaser->getKey())
            ->and($record->items()->count())->toBe(1);
    });
});

it('refuses a purchase request with no lines', function (): void {
    $f = buyingFixture();

    $this->actingAs(buyer($f))->post('/purchase-requests', ['lines' => []])->assertSessionHasErrors('lines');

    expect($this->withCompany($f['company'], fn (): int => PurchaseRequest::query()->count()))->toBe(0);
});

it('refuses to approve a purchase request without purchasing.approve', function (): void {
    $f = buyingFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::Storekeeper, 'purchasing.view', DataScope::Company);
    grant($f['company'], CompanyRole::Storekeeper, 'purchasing.create', DataScope::Company);

    $record = $this->withCompany($f['company'], fn (): PurchaseRequest => PurchaseRequest::create([
        'branch_id' => $f['branch']->getKey(),
        'requested_by' => $f['owner']->getKey(),
        'reference' => 'PR-DECIDE',
        'status' => 'pending',
    ]));

    $this->withCompany($f['company'], function () use ($f, $storekeeper): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($storekeeper->can('purchasing.view'))->toBeTrue('the storekeeper must be able to open it')
            ->and($storekeeper->can('purchasing.approve'))->toBeFalse('a storekeeper must not hold purchasing.approve');
    });

    $this->actingAs($storekeeper)
        ->get("/purchase-requests/{$record->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('permissions.approve', false));

    $this->actingAs($storekeeper)
        ->post("/purchase-requests/{$record->getKey()}/decide", ['decision' => 'approved'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($record): void {
        expect($record->fresh()?->status)->toBe('pending');
    });
});

it('lets a purchaser submit and approve a request through to approved', function (): void {
    $f = buyingFixture();
    $purchaser = buyer($f);

    $record = $this->withCompany($f['company'], fn (): PurchaseRequest => PurchaseRequest::create([
        'branch_id' => $f['branch']->getKey(),
        'requested_by' => $purchaser->getKey(),
        'reference' => 'PR-FLOW',
        'status' => 'draft',
    ]));

    $this->actingAs($purchaser)->post("/purchase-requests/{$record->getKey()}/submit")->assertRedirect();

    $this->withCompany($f['company'], function () use ($record): void {
        expect($record->fresh()?->status)->toBe('pending');
    });

    $this->actingAs($purchaser)
        ->post("/purchase-requests/{$record->getKey()}/decide", ['decision' => 'approved'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($record): void {
        expect($record->fresh()?->status)->toBe('approved');
    });
});

it('raises a purchase order and totals it from its lines', function (): void {
    $f = buyingFixture();

    $this->actingAs(buyer($f))->post('/purchase-orders', [
        'supplier_id' => $f['supplier']->getKey(),
        'warehouse_id' => $f['warehouse']->getKey(),
        'branch_id' => $f['branch']->getKey(),
        'currency' => 'MYR',
        'lines' => [['product_variant_id' => $f['variant']->getKey(), 'quantity' => '10', 'unit_cost' => '60']],
    ])->assertRedirect();

    $this->withCompany($f['company'], function (): void {
        $order = PurchaseOrder::query()->firstOrFail();

        expect($order->reference)->toStartWith('PO-')
            ->and($order->status)->toBe('draft')
            ->and((string) $order->total)->toBe('600.0000')
            ->and((string) $order->items()->first()?->line_total)->toBe('600.0000');
    });
});

it('receives goods, moves stock and marks the order received', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);

    $this->actingAs(buyer($f))->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'supplier_do_number' => 'DO-99',
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $this->withCompany($f['company'], function () use ($order): void {
        $stock = Stock::query()->firstOrFail();

        expect((string) $stock->on_hand)->toBe('10.0000')
            ->and($order->fresh()?->status)->toBe('received')
            ->and(GoodsReceipt::query()->count())->toBe(1);
    });
});

it('refuses to receive more than the order still has outstanding', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);

    $this->actingAs(buyer($f))->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '12']],
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function (): void {
        expect(Stock::query()->count())->toBe(0)
            ->and(GoodsReceipt::query()->count())->toBe(0);
    });
});

it('refuses to receive against an order nobody has approved', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);

    $this->withCompany($f['company'], fn () => $order->forceFill(['status' => 'draft'])->save());

    $this->actingAs(buyer($f))->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '1']],
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function (): void {
        expect(GoodsReceipt::query()->count())->toBe(0);
    });
});

it('refuses to receive goods from a role that may only look at purchasing', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);

    $accountant = person($f['company'], CompanyRole::Accountant, 'books@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::Accountant, 'purchasing.view', DataScope::Company);

    $this->withCompany($f['company'], function () use ($f, $accountant): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($accountant->can('purchasing.view'))->toBeTrue('the accountant must be able to open the order')
            ->and($accountant->can('purchasing.receive'))->toBeFalse('an accountant must not hold purchasing.receive');
    });

    $this->actingAs($accountant)->get("/purchase-orders/{$order->getKey()}")->assertOk();

    $this->actingAs($accountant)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '1']],
    ])->assertForbidden();

    $this->withCompany($f['company'], function (): void {
        expect(GoodsReceipt::query()->count())->toBe(0);
    });
});

it('apportions a landed cost across the receipt and explains it', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);
    $purchaser = buyer($f);

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $receipt = $this->withCompany($f['company'], fn (): GoodsReceipt => GoodsReceipt::query()->firstOrFail());

    $this->actingAs($purchaser)->post("/goods-receipts/{$receipt->getKey()}/costs", [
        'kind' => 'freight',
        'allocation' => 'by_value',
        'amount' => '100',
        'note' => 'Sea freight',
    ])->assertRedirect()->assertSessionMissing('error');

    $this->actingAs($purchaser)
        ->get("/goods-receipts/{$receipt->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Purchasing/Receipts/Show')
            ->where('items.0.landed_unit_cost', '70.0000')
            ->has('items.0.landed_cost_basis.components', 1)
            ->has('costs', 1));
});

it('refuses a landed cost from a role without purchasing.receive', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);

    $this->actingAs(buyer($f))->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $receipt = $this->withCompany($f['company'], fn (): GoodsReceipt => GoodsReceipt::query()->firstOrFail());

    $accountant = person($f['company'], CompanyRole::Accountant, 'books2@acme.test', $f['branch']);
    grant($f['company'], CompanyRole::Accountant, 'purchasing.view', DataScope::Company);

    $this->actingAs($accountant)
        ->post("/goods-receipts/{$receipt->getKey()}/costs", ['kind' => 'freight', 'allocation' => 'by_value', 'amount' => '100'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($receipt): void {
        expect(GoodsReceiptCost::query()->where('goods_receipt_id', $receipt->getKey())->count())->toBe(0);
    });
});

it('records a bill and shows a clean three-way match when it agrees with the order', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);
    $purchaser = buyer($f);

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/bills", [
        'supplier_invoice_number' => 'SI-1',
        'billed_at' => now()->toDateString(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10', 'unit_cost' => '60']],
    ])->assertRedirect();

    $bill = $this->withCompany($f['company'], fn (): SupplierBill => SupplierBill::query()->firstOrFail());

    $this->actingAs($purchaser)
        ->get("/supplier-bills/{$bill->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Purchasing/Bills/Show')
            ->where('match.matched', true)
            ->where('bill.total', '600.0000'));
});

it('disputes rather than approves a bill that does not match the order', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);
    $purchaser = buyer($f);

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/bills", [
        'supplier_invoice_number' => 'SI-BAD',
        'billed_at' => now()->toDateString(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10', 'unit_cost' => '75']],
    ])->assertRedirect();

    $bill = $this->withCompany($f['company'], fn (): SupplierBill => SupplierBill::query()->firstOrFail());

    $this->actingAs($purchaser)
        ->get("/supplier-bills/{$bill->getKey()}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('match.matched', false)
            ->where('match.discrepancies.0.kind', 'price_variance'));

    $this->actingAs($purchaser)
        ->post("/supplier-bills/{$bill->getKey()}/approve")
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($bill): void {
        expect($bill->fresh()?->status)->toBe('disputed');
    });
});

it('refuses to pay a bill nobody has approved', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);
    $purchaser = buyer($f);

    grant($f['company'], CompanyRole::Purchaser, 'payments.create', DataScope::Company);

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/bills", [
        'supplier_invoice_number' => 'SI-2',
        'billed_at' => now()->toDateString(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10', 'unit_cost' => '60']],
    ])->assertRedirect();

    $bill = $this->withCompany($f['company'], fn (): SupplierBill => SupplierBill::query()->firstOrFail());

    $this->actingAs($purchaser)
        ->post("/supplier-bills/{$bill->getKey()}/payments", ['amount' => '600', 'method' => 'bank_transfer'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($bill): void {
        expect((string) $bill->fresh()?->paid_amount)->toBe('0.0000');
    });
});

it('approves a matching bill and pays it down to settled', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);
    $purchaser = buyer($f);

    grant($f['company'], CompanyRole::Purchaser, 'payments.create', DataScope::Company);

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/bills", [
        'supplier_invoice_number' => 'SI-3',
        'billed_at' => now()->toDateString(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10', 'unit_cost' => '60']],
    ])->assertRedirect();

    $bill = $this->withCompany($f['company'], fn (): SupplierBill => SupplierBill::query()->firstOrFail());

    $this->actingAs($purchaser)->post("/supplier-bills/{$bill->getKey()}/approve")->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($bill): void {
        expect($bill->fresh()?->status)->toBe('approved');
    });

    $this->actingAs($purchaser)
        ->post("/supplier-bills/{$bill->getKey()}/payments", ['amount' => '600', 'method' => 'bank_transfer'])
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($bill): void {
        expect((string) $bill->fresh()?->paid_amount)->toBe('600.0000')
            ->and($bill->fresh()?->status)->toBe('paid');
    });
});

it('refuses a supplier payment from a role without payments.create', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);
    $purchaser = buyer($f);

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/bills", [
        'supplier_invoice_number' => 'SI-4',
        'billed_at' => now()->toDateString(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10', 'unit_cost' => '60']],
    ])->assertRedirect();

    $bill = $this->withCompany($f['company'], fn (): SupplierBill => SupplierBill::query()->firstOrFail());

    $this->actingAs($purchaser)->post("/supplier-bills/{$bill->getKey()}/approve")->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($f, $purchaser): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($purchaser->can('purchasing.approve'))->toBeTrue('the purchaser approved the bill a moment ago')
            ->and($purchaser->can('payments.create'))->toBeFalse('a purchaser must not hold payments.create by default');
    });

    $this->actingAs($purchaser)
        ->post("/supplier-bills/{$bill->getKey()}/payments", ['amount' => '600', 'method' => 'bank_transfer'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($bill): void {
        expect((string) $bill->fresh()?->paid_amount)->toBe('0.0000');
    });
});

it('refuses a supplier payment larger than the outstanding balance', function (): void {
    $f = buyingFixture();
    [$order, $item] = approvedOrder($f);
    $purchaser = buyer($f);

    grant($f['company'], CompanyRole::Purchaser, 'payments.create', DataScope::Company);

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/receipts", [
        'warehouse_id' => $f['warehouse']->getKey(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10']],
    ])->assertRedirect();

    $this->actingAs($purchaser)->post("/purchase-orders/{$order->getKey()}/bills", [
        'supplier_invoice_number' => 'SI-5',
        'billed_at' => now()->toDateString(),
        'lines' => [['purchase_order_item_id' => $item->getKey(), 'quantity' => '10', 'unit_cost' => '60']],
    ])->assertRedirect();

    $bill = $this->withCompany($f['company'], fn (): SupplierBill => SupplierBill::query()->firstOrFail());

    $this->actingAs($purchaser)->post("/supplier-bills/{$bill->getKey()}/approve")->assertSessionMissing('error');

    $this->actingAs($purchaser)
        ->post("/supplier-bills/{$bill->getKey()}/payments", ['amount' => '900', 'method' => 'bank_transfer'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($bill): void {
        expect((string) $bill->fresh()?->paid_amount)->toBe('0.0000');
    });
});

it('never shows a purchase order belonging to another company', function (): void {
    $f = buyingFixture();
    $purchaser = buyer($f);

    $other = Company::create(['name' => 'Rival', 'slug' => 'rival-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($other);

    $this->withCompany($other, function (): void {
        $warehouse = Warehouse::create(['code' => 'W', 'name' => 'W']);
        $supplier = Supplier::create(['code' => 'S', 'name' => 'Rival Supply']);

        PurchaseOrder::create([
            'supplier_id' => $supplier->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'reference' => 'PO-RIVAL',
            'status' => 'approved',
        ]);
    });

    approvedOrder($f);

    $response = $this->actingAs($purchaser)->get('/purchase-orders');

    $response->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1));

    expect($response->getContent())->not->toContain('PO-RIVAL');
});

it('refuses the approvals inbox to a role without purchasing.approve', function (): void {
    $f = buyingFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store2@acme.test', $f['branch']);

    $this->actingAs($storekeeper)->get('/dashboard')->assertOk();
    $this->actingAs($storekeeper)->get('/approvals')->assertForbidden();

    $this->actingAs(buyer($f))->get('/approvals')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Purchasing/Approvals/Index'));
});

it('shows a branch-scoped buyer only the purchase orders raised in their branch', function (): void {
    $f = buyingFixture();

    $northBranch = $this->withCompany($f['company'], fn (): Branch => Branch::create(['code' => 'NTH', 'name' => 'North']));
    $northBuyer = person($f['company'], CompanyRole::Purchaser, 'northpo@acme.test', $northBranch);

    grant($f['company'], CompanyRole::Purchaser, 'purchasing.view', DataScope::Branch);

    $this->withCompany($f['company'], function () use ($f, $northBranch): void {
        PurchaseOrder::create([
            'supplier_id' => $f['supplier']->getKey(),
            'warehouse_id' => $f['warehouse']->getKey(),
            'branch_id' => $f['branch']->getKey(),
            'reference' => 'PO-HQ',
            'status' => 'approved',
        ]);

        PurchaseOrder::create([
            'supplier_id' => $f['supplier']->getKey(),
            'warehouse_id' => $f['warehouse']->getKey(),
            'branch_id' => $northBranch->getKey(),
            'reference' => 'PO-NORTH',
            'status' => 'approved',
        ]);
    });

    $response = $this->actingAs($northBuyer)->get('/purchase-orders');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('orders.data', 1)
        ->where('orders.data.0.reference', 'PO-NORTH'));

    expect($response->getContent())->not->toContain('PO-HQ');
});

it('refuses to open a purchase order outside the buyer branch scope', function (): void {
    $f = buyingFixture();

    $northBranch = $this->withCompany($f['company'], fn (): Branch => Branch::create(['code' => 'NTH', 'name' => 'North']));
    $northBuyer = person($f['company'], CompanyRole::Purchaser, 'northpo2@acme.test', $northBranch);

    grant($f['company'], CompanyRole::Purchaser, 'purchasing.view', DataScope::Branch);

    [$hqOrder] = approvedOrder($f);

    $this->actingAs($northBuyer)->get("/purchase-orders/{$hqOrder->getKey()}")->assertForbidden();
});
