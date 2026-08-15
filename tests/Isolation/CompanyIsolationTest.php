<?php

declare(strict_types=1);

use App\Exceptions\CrossCompanyAccessException;
use App\Exceptions\MissingCompanyContextException;
use App\Models\ApprovalAction;
use App\Models\ApprovalFlow;
use App\Models\ApprovalLevel;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\BundleItem;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyModuleSetting;
use App\Models\CompanyUser;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\CustomerGroup;
use App\Models\Department;
use App\Models\DocumentSequence;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Module;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\PromotionRule;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\SupplierContact;
use App\Models\SupplierPayment;
use App\Models\TaxRate;
use App\Models\TierPrice;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/** @return array<int, class-string<Model>> */
function scopedModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/app/Models') as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $class = 'App\\Models\\'.$relative;

        if (! class_exists($class)) {
            continue;
        }

        if (! in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
            continue;
        }

        $models[] = $class;
    }

    sort($models);

    return $models;
}

function newCustomer(string $suffix): Customer
{
    return Customer::create(['code' => 'CU-'.$suffix, 'name' => 'Customer '.$suffix]);
}

function newSupplier(string $suffix): Supplier
{
    return Supplier::create(['code' => 'SP-'.$suffix, 'name' => 'Supplier '.$suffix]);
}

function newProduct(string $suffix): Product
{
    return Product::create(['sku' => 'PR-'.$suffix, 'name' => 'Product '.$suffix]);
}

function newVariant(string $suffix): ProductVariant
{
    return ProductVariant::create([
        'product_id' => newProduct($suffix.'v')->getKey(),
        'sku' => 'VR-'.$suffix,
        'selling_price' => '10.0000',
    ]);
}

function newOrder(string $suffix): Order
{
    return Order::create([
        'order_number' => 'SO-'.$suffix.'-'.str()->random(4),
        'customer_name' => 'Walk-in '.$suffix,
    ]);
}

function newWarehouse(string $suffix): Warehouse
{
    return Warehouse::create(['code' => 'WH-'.$suffix.'-'.str()->random(4), 'name' => 'Warehouse '.$suffix]);
}

function newStock(string $suffix): Stock
{
    return Stock::create([
        'warehouse_id' => newWarehouse($suffix.'st')->getKey(),
        'product_variant_id' => newVariant($suffix.'sv')->getKey(),
    ]);
}

function newUser(string $suffix): User
{
    return User::create([
        'name' => 'User '.$suffix,
        'email' => strtolower($suffix).str()->random(4).'@example.test',
        'password' => 'secret-password',
    ]);
}

function newPurchaseOrder(string $suffix): PurchaseOrder
{
    return PurchaseOrder::create([
        'supplier_id' => newSupplier($suffix.'sup')->getKey(),
        'reference' => 'PO-'.$suffix.'-'.str()->random(4),
    ]);
}

function newPurchaseOrderItem(string $suffix): PurchaseOrderItem
{
    return PurchaseOrderItem::create([
        'purchase_order_id' => newPurchaseOrder($suffix)->getKey(),
        'product_variant_id' => newVariant($suffix.'poix')->getKey(),
        'sku' => 'SKU-'.$suffix,
        'product_name' => 'Item '.$suffix,
        'quantity' => '10',
        'unit_cost' => '5.0000',
        'line_total' => '50.0000',
    ]);
}

function newGoodsReceipt(string $suffix): GoodsReceipt
{
    return GoodsReceipt::create([
        'purchase_order_id' => newPurchaseOrder($suffix.'grx')->getKey(),
        'warehouse_id' => newWarehouse($suffix.'grx')->getKey(),
        'reference' => 'GRN-'.$suffix.'-'.str()->random(4),
        'received_at' => now(),
    ]);
}

function newSupplierBill(string $suffix): SupplierBill
{
    return SupplierBill::create([
        'purchase_order_id' => newPurchaseOrder($suffix.'sbx')->getKey(),
        'supplier_id' => newSupplier($suffix.'sbx')->getKey(),
        'reference' => 'BILL-'.$suffix.'-'.str()->random(4),
        'supplier_invoice_number' => 'INV-'.$suffix.'-'.str()->random(4),
        'billed_at' => now(),
    ]);
}

function newApprovalFlow(string $suffix): ApprovalFlow
{
    return ApprovalFlow::create([
        'code' => 'AF-'.$suffix.'-'.str()->random(4),
        'name' => 'Flow '.$suffix,
        'approvable_type' => PurchaseOrder::class,
    ]);
}

function seedRowFor(string $class, Company $company): Model
{
    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($class): Model {
        $suffix = str()->random(6);

        $attributes = match ($class) {
            AuditLog::class => ['action' => 'created', 'module' => 'test'],
            Branch::class => ['code' => 'BR-'.$suffix, 'name' => 'Branch '.$suffix],
            Department::class => ['code' => 'DP-'.$suffix, 'name' => 'Dept '.$suffix],
            CompanyUser::class => [
                'user_id' => User::create([
                    'name' => 'User '.$suffix,
                    'email' => strtolower($suffix).'@example.test',
                    'password' => 'secret-password',
                ])->getKey(),
                'role' => 'staff',
            ],
            CompanyModuleSetting::class => [
                'module_key' => Module::firstOrCreate(
                    ['key' => 'orders'],
                    ['name' => 'Orders', 'is_core' => true]
                )->key,
                'enabled' => true,
            ],
            Role::class => ['name' => 'role-'.$suffix, 'guard_name' => 'web'],
            CustomerGroup::class => ['code' => 'CG-'.$suffix, 'name' => 'Group '.$suffix],
            Customer::class => ['code' => 'CU-'.$suffix, 'name' => 'Customer '.$suffix],
            CustomerContact::class => ['customer_id' => newCustomer($suffix)->getKey(), 'name' => 'Contact '.$suffix],
            CustomerAddress::class => ['customer_id' => newCustomer($suffix)->getKey(), 'line1' => '1 Jalan '.$suffix],
            Supplier::class => ['code' => 'SP-'.$suffix, 'name' => 'Supplier '.$suffix],
            SupplierContact::class => ['supplier_id' => newSupplier($suffix)->getKey(), 'name' => 'Contact '.$suffix],
            SupplierAddress::class => ['supplier_id' => newSupplier($suffix)->getKey(), 'line1' => '2 Jalan '.$suffix],
            Category::class => ['code' => 'CT-'.$suffix, 'name' => 'Category '.$suffix],
            Brand::class => ['code' => 'BD-'.$suffix, 'name' => 'Brand '.$suffix],
            UnitOfMeasure::class => ['code' => 'UOM-'.$suffix, 'name' => 'Unit '.$suffix],
            TaxRate::class => ['code' => 'TX-'.$suffix, 'name' => 'Tax '.$suffix, 'rate_percent' => '6'],
            Product::class => ['sku' => 'PR-'.$suffix, 'name' => 'Product '.$suffix],
            ProductVariant::class => ['product_id' => newProduct($suffix)->getKey(), 'sku' => 'VR-'.$suffix],
            ProductImage::class => ['product_id' => newProduct($suffix)->getKey(), 'path' => 'products/'.$suffix.'.webp'],
            ProductBundle::class => ['product_id' => newProduct($suffix)->getKey(), 'pricing_mode' => 'fixed', 'fixed_price' => '99.0000'],
            BundleItem::class => [
                'product_bundle_id' => ProductBundle::create([
                    'product_id' => newProduct($suffix.'b')->getKey(),
                    'pricing_mode' => 'sum_of_components',
                ])->getKey(),
                'product_variant_id' => newVariant($suffix)->getKey(),
                'quantity' => '2',
            ],
            PriceList::class => ['code' => 'PL-'.$suffix, 'name' => 'Price list '.$suffix],
            PriceListItem::class => [
                'price_list_id' => PriceList::create(['code' => 'PL-'.$suffix, 'name' => 'List '.$suffix])->getKey(),
                'product_variant_id' => newVariant($suffix)->getKey(),
                'price' => '10.0000',
            ],
            TierPrice::class => ['product_variant_id' => newVariant($suffix)->getKey(), 'min_quantity' => '10', 'price' => '9.0000'],
            PromotionRule::class => ['code' => 'PM-'.$suffix, 'name' => 'Promo '.$suffix, 'discount_type' => 'percent', 'discount_value' => '5'],
            DocumentSequence::class => ['key' => 'seq-'.$suffix, 'prefix' => 'SQ'],
            Order::class => ['order_number' => 'SO-'.$suffix, 'customer_name' => 'Walk-in '.$suffix],
            OrderItem::class => [
                'order_id' => newOrder($suffix)->getKey(),
                'sku' => 'VR-'.$suffix,
                'product_name' => 'Product '.$suffix,
                'quantity' => '1',
                'unit_price' => '10.0000',
                'line_total' => '10.0000',
            ],
            OrderEvent::class => [
                'order_id' => newOrder($suffix)->getKey(),
                'event' => 'order.created',
                'summary' => 'Created for the isolation suite.',
            ],
            Warehouse::class => ['code' => 'WH-'.$suffix, 'name' => 'Warehouse '.$suffix],
            PurchaseRequest::class => ['reference' => 'PR-'.$suffix],
            PurchaseRequestItem::class => [
                'purchase_request_id' => PurchaseRequest::create(['reference' => 'PR2-'.$suffix])->getKey(),
                'product_variant_id' => newVariant($suffix.'pr')->getKey(),
                'quantity' => '5',
            ],
            PurchaseOrder::class => [
                'supplier_id' => newSupplier($suffix.'po')->getKey(),
                'reference' => 'PO-'.$suffix,
            ],
            PurchaseOrderItem::class => [
                'purchase_order_id' => newPurchaseOrder($suffix)->getKey(),
                'product_variant_id' => newVariant($suffix.'poi')->getKey(),
                'sku' => 'SKU-'.$suffix,
                'product_name' => 'Item '.$suffix,
                'quantity' => '10',
                'unit_cost' => '5.0000',
                'line_total' => '50.0000',
            ],
            GoodsReceipt::class => [
                'purchase_order_id' => newPurchaseOrder($suffix.'gr')->getKey(),
                'warehouse_id' => newWarehouse($suffix.'gr')->getKey(),
                'reference' => 'GRN-'.$suffix,
                'received_at' => now(),
            ],
            GoodsReceiptItem::class => [
                'goods_receipt_id' => newGoodsReceipt($suffix)->getKey(),
                'purchase_order_item_id' => newPurchaseOrderItem($suffix.'gri')->getKey(),
                'product_variant_id' => newVariant($suffix.'gri')->getKey(),
                'quantity' => '3',
                'unit_cost' => '5.0000',
            ],
            SupplierBill::class => [
                'purchase_order_id' => newPurchaseOrder($suffix.'sb')->getKey(),
                'supplier_id' => newSupplier($suffix.'sb')->getKey(),
                'reference' => 'BILL-'.$suffix,
                'supplier_invoice_number' => 'INV-'.$suffix,
                'billed_at' => now(),
            ],
            SupplierBillItem::class => [
                'supplier_bill_id' => newSupplierBill($suffix)->getKey(),
                'purchase_order_item_id' => newPurchaseOrderItem($suffix.'sbi')->getKey(),
                'quantity' => '2',
                'unit_cost' => '5.0000',
                'line_total' => '10.0000',
            ],
            SupplierPayment::class => [
                'supplier_bill_id' => newSupplierBill($suffix.'sp')->getKey(),
                'amount' => '10.0000',
                'paid_at' => now(),
            ],
            ApprovalFlow::class => [
                'code' => 'AF-'.$suffix,
                'name' => 'Flow '.$suffix,
                'approvable_type' => PurchaseOrder::class,
            ],
            ApprovalLevel::class => [
                'approval_flow_id' => newApprovalFlow($suffix)->getKey(),
                'approver_user_id' => newUser($suffix.'al')->getKey(),
                'sequence' => 1,
            ],
            ApprovalRequest::class => [
                'approval_flow_id' => newApprovalFlow($suffix.'ar')->getKey(),
                'approvable_type' => PurchaseOrder::class,
                'approvable_id' => newPurchaseOrder($suffix.'ar')->getKey(),
            ],
            ApprovalAction::class => [
                'approval_request_id' => ApprovalRequest::create([
                    'approval_flow_id' => newApprovalFlow($suffix.'aa')->getKey(),
                    'approvable_type' => PurchaseOrder::class,
                    'approvable_id' => newPurchaseOrder($suffix.'aa')->getKey(),
                ])->getKey(),
                'sequence' => 1,
                'action' => 'submit',
            ],
            Stock::class => [
                'warehouse_id' => newWarehouse($suffix)->getKey(),
                'product_variant_id' => newVariant($suffix.'s')->getKey(),
            ],
            StockMovement::class => [
                'stock_id' => newStock($suffix)->getKey(),
                'quantity_delta' => '5',
                'balance_after' => '5',
                'reason' => 'received',
            ],
            StockReservation::class => [
                'stock_id' => newStock($suffix)->getKey(),
                'quantity' => '1',
                'status' => 'held',
            ],
            StockAdjustment::class => [
                'warehouse_id' => newWarehouse($suffix)->getKey(),
                'reference' => 'ADJ-'.$suffix,
                'reason' => 'stock_take',
            ],
            StockAdjustmentItem::class => [
                'stock_adjustment_id' => StockAdjustment::create([
                    'warehouse_id' => newWarehouse($suffix.'a')->getKey(),
                    'reference' => 'ADJ2-'.$suffix,
                    'reason' => 'damaged',
                ])->getKey(),
                'product_variant_id' => newVariant($suffix.'ai')->getKey(),
                'quantity_delta' => '-2',
            ],
            StockTransfer::class => [
                'from_warehouse_id' => newWarehouse($suffix.'f')->getKey(),
                'to_warehouse_id' => newWarehouse($suffix.'t')->getKey(),
                'reference' => 'TRF-'.$suffix,
            ],
            StockTransferItem::class => [
                'stock_transfer_id' => StockTransfer::create([
                    'from_warehouse_id' => newWarehouse($suffix.'f2')->getKey(),
                    'to_warehouse_id' => newWarehouse($suffix.'t2')->getKey(),
                    'reference' => 'TRF2-'.$suffix,
                ])->getKey(),
                'product_variant_id' => newVariant($suffix.'ti')->getKey(),
                'quantity' => '3',
            ],
            Payment::class => [
                'order_id' => newOrder($suffix)->getKey(),
                'amount' => '10.0000',
                'received_at' => now(),
            ],
            RolePermissionScope::class => [
                'role_id' => Role::create([
                    'name' => 'role-'.$suffix,
                    'guard_name' => 'web',
                ])->getKey(),
                'permission_id' => Permission::firstOrCreate(
                    ['name' => 'orders.view', 'guard_name' => 'web'],
                    ['group' => 'orders', 'ability' => 'view']
                )->getKey(),
                'scope' => 'own',
            ],
            default => throw new RuntimeException(
                "[{$class}] carries BelongsToCompany but has no seed recipe in seedRowFor(). ".
                'Add one so the isolation suite covers it.'
            ),
        };

        return $class::create($attributes);
    });
}

it('discovers at least one company-scoped model', function (): void {
    expect(scopedModels())->not->toBeEmpty();
});

it('has a seed recipe for every company-scoped model', function (string $class): void {
    $company = $this->company();

    expect(seedRowFor($class, $company))->toBeInstanceOf($class);
})->with(scopedModels());

it('never returns another company\'s rows through a normal query', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    seedRowFor($class, $a);
    seedRowFor($class, $b);

    $visible = $this->withCompany($a, fn (): int => $class::query()->count());
    $actual = $class::acrossCompanies()->where('company_id', $a->getKey())->count();

    expect($visible)->toBe($actual)
        ->and($visible)->toBeGreaterThan(0);
})->with(scopedModels());

it('never finds another company\'s record by primary key', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    $foreign = seedRowFor($class, $b);

    $found = $this->withCompany($a, fn () => $class::query()->find($foreign->getKey()));

    expect($found)->toBeNull("[{$class}] leaked a row across companies via find()");
})->with(scopedModels());

it('stamps the bound company on create rather than trusting input', function (string $class): void {
    $a = $this->company('Company A');

    $row = seedRowFor($class, $a);

    expect($row->getAttribute('company_id'))->toBe($a->getKey());
})->with(scopedModels());

it('refuses to create a row for a company other than the bound one', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    $row = seedRowFor($class, $a);
    $attributes = collect($row->getAttributes())
        ->except([$row->getKeyName(), 'created_at', 'updated_at'])
        ->put('company_id', $b->getKey())
        ->all();

    expect(fn () => $this->withCompany($a, function () use ($class, $attributes): void {
        $model = new $class;
        $model->forceFill($attributes);
        $model->save();
    }))->toThrow(CrossCompanyAccessException::class);
})->with(scopedModels());

it('refuses to move an existing record between companies', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    $row = seedRowFor($class, $a);

    expect(fn () => $this->withCompany($a, function () use ($row, $b): void {
        $row->company_id = $b->getKey();
        $row->save();
    }))->toThrow(CrossCompanyAccessException::class);
})->with(scopedModels());

it('fails closed when no company is bound', function (string $class): void {
    app(CompanyContext::class)->forget();

    expect(fn (): int => $class::query()->count())
        ->toThrow(MissingCompanyContextException::class);
})->with(scopedModels());

it('keeps company_id out of mass assignment', function (string $class): void {
    $fillable = (new $class)->getFillable();

    expect(in_array('company_id', $fillable, true))
        ->toBeFalse("[{$class}] exposes company_id to mass assignment");
})->with(scopedModels());

it('declares company_id NOT NULL on every scoped table', function (string $class): void {
    $table = (new $class)->getTable();

    $nullable = DB::selectOne(
        'select is_nullable from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        ['public', $table, 'company_id']
    );

    expect($nullable)->not->toBeNull("[{$table}] has no company_id column")
        ->and($nullable->is_nullable)->toBe('NO', "[{$table}].company_id is nullable");
})->with(scopedModels());

it('does not carry company context between tests', function (): void {
    expect(app(CompanyContext::class)->hasContext())->toBeFalse();
});

it('restores the previous company after runAs unwinds', function (): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');
    $context = app(CompanyContext::class);

    $context->set($a->getKey());
    $context->runAs($b->getKey(), fn () => null);

    expect($context->id())->toBe($a->getKey());
});

it('restores the previous company even when the callback throws', function (): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');
    $context = app(CompanyContext::class);

    $context->set($a->getKey());

    try {
        $context->runAs($b->getKey(), fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($context->id())->toBe($a->getKey());
});
