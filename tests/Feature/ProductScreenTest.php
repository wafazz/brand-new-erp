<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
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

it('lists the catalogue to a role that holds products.view', function (): void {
    $f = routeFixture();

    $this->withCompany($f['company'], function (): void {
        $product = Product::create(['sku' => 'SKU-1', 'name' => 'Widget']);
        ProductVariant::create(['product_id' => $product->getKey(), 'sku' => 'SKU-1-A', 'name' => 'Default', 'is_default' => true]);
    });

    $this->actingAs($f['owner'])
        ->get('/products')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalogue/Products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Widget')
            ->where('products.data.0.variants_count', 1)
        );
});

it('refuses the catalogue to a role without products.view', function (): void {
    $f = routeFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $accountant): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($accountant->can('products.view'))->toBeFalse('accountant must not hold products.view');
    });

    $this->actingAs($accountant)->get('/dashboard')->assertOk();
    $this->actingAs($accountant)->get('/products')->assertForbidden();
});

it('never shows a product belonging to another company', function (): void {
    $f = routeFixture();

    $other = Company::create(['name' => 'Rival Sdn Bhd', 'slug' => 'rival-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($other);

    $this->withCompany($other, function (): void {
        Product::create(['sku' => 'RIVAL-1', 'name' => 'Rival Widget']);
    });

    $this->withCompany($f['company'], function (): void {
        Product::create(['sku' => 'OURS-1', 'name' => 'Our Widget']);
    });

    $response = $this->actingAs($f['owner'])->get('/products');

    $response->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', 1)
        ->where('products.data.0.name', 'Our Widget'));

    expect($response->getContent())->not->toContain('Rival Widget');
});

it('creates a product with its variants and marks the first one default', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])->post('/products', [
        'sku' => 'TS-100',
        'name' => 'T-Shirt',
        'type' => 'product',
        'status' => 'active',
        'is_stock_tracked' => true,
        'variants' => [
            ['sku' => 'TS-100-S', 'name' => 'Small', 'cost_price' => '5', 'selling_price' => '15'],
            ['sku' => 'TS-100-M', 'name' => 'Medium', 'cost_price' => '5', 'selling_price' => '15'],
        ],
    ])->assertRedirect();

    $this->withCompany($f['company'], function (): void {
        $product = Product::query()->where('sku', 'TS-100')->firstOrFail();

        expect($product->has_variants)->toBeTrue()
            ->and($product->variants()->count())->toBe(2)
            ->and($product->variants()->where('is_default', true)->count())->toBe(1)
            ->and($product->variants()->where('is_default', true)->value('sku'))->toBe('TS-100-S');
    });
});

it('refuses a product whose two variants share a SKU', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])->post('/products', [
        'sku' => 'DUP-1',
        'name' => 'Duplicate',
        'type' => 'product',
        'status' => 'active',
        'is_stock_tracked' => true,
        'variants' => [
            ['sku' => 'SAME', 'name' => 'A', 'cost_price' => '1', 'selling_price' => '2'],
            ['sku' => 'SAME', 'name' => 'B', 'cost_price' => '1', 'selling_price' => '2'],
        ],
    ])->assertStatus(422);

    expect($this->withCompany($f['company'], fn (): int => Product::query()->count()))->toBe(0);
});

it('refuses a product with no variants at all', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->post('/products', ['sku' => 'X', 'name' => 'X', 'type' => 'product', 'status' => 'active', 'is_stock_tracked' => true, 'variants' => []])
        ->assertSessionHasErrors('variants');

    expect($this->withCompany($f['company'], fn (): int => Product::query()->count()))->toBe(0);
});

it('deactivates rather than deletes a dropped variant that still holds stock', function (): void {
    $f = routeFixture();

    [$product, $kept, $dropped] = $this->withCompany($f['company'], function (): array {
        $product = Product::create(['sku' => 'P-1', 'name' => 'Two Variant', 'has_variants' => true]);
        $kept = ProductVariant::create(['product_id' => $product->getKey(), 'sku' => 'P-1-A', 'name' => 'A', 'is_default' => true]);
        $dropped = ProductVariant::create(['product_id' => $product->getKey(), 'sku' => 'P-1-B', 'name' => 'B']);

        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_variant_id' => $dropped->getKey()]);
        Stock::query()->where('product_variant_id', $dropped->getKey())->update(['on_hand' => '7']);

        return [$product, $kept, $dropped];
    });

    $this->actingAs($f['owner'])->put("/products/{$product->getKey()}", [
        'sku' => 'P-1',
        'name' => 'Two Variant',
        'type' => 'product',
        'status' => 'active',
        'is_stock_tracked' => true,
        'variants' => [
            ['id' => $kept->getKey(), 'sku' => 'P-1-A', 'name' => 'A', 'cost_price' => '0', 'selling_price' => '0', 'is_default' => true, 'is_active' => true],
        ],
    ])->assertRedirect();

    $this->withCompany($f['company'], function () use ($dropped): void {
        $fresh = ProductVariant::query()->find($dropped->getKey());

        expect($fresh)->not->toBeNull('the variant must survive because stock still refers to it')
            ->and($fresh->is_active)->toBeFalse();
    });
});

it('reports on-hand stock per variant on the product page', function (): void {
    $f = routeFixture();

    $product = $this->withCompany($f['company'], function (): Product {
        $product = Product::create(['sku' => 'S-1', 'name' => 'Stocked']);
        $variant = ProductVariant::create(['product_id' => $product->getKey(), 'sku' => 'S-1-A', 'name' => 'A', 'is_default' => true]);

        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_variant_id' => $variant->getKey()]);
        Stock::query()->where('product_variant_id', $variant->getKey())->update(['on_hand' => '12.5000', 'reserved' => '2.0000']);

        return $product;
    });

    $this->actingAs($f['owner'])
        ->get("/products/{$product->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalogue/Products/Show')
            ->where('variants.0.on_hand', '12.5000')
            ->where('variants.0.reserved', '2.0000')
        );
});
