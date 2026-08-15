<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Enums\FulfilmentStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

function screenOrderFor(Company $company, User $owner, string $number, string $total = '100'): Order
{
    return test()->withCompany($company, function () use ($owner, $number, $total): Order {
        $order = Order::create([
            'order_number' => $number,
            'owner_user_id' => $owner->getKey(),
            'customer_name' => 'Walk-in customer',
            'placed_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->getKey(),
            'sku' => 'LINE-1',
            'product_name' => 'Line item',
            'quantity' => '1',
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        $order->forceFill(['subtotal' => $total, 'total' => $total])->save();

        return $order->refresh();
    });
}

function screenVariant(Company $company, string $price = '50'): ProductVariant
{
    return test()->withCompany($company, function () use ($price): ProductVariant {
        $product = Product::create(['sku' => 'SELL-'.str()->random(4), 'name' => 'Sellable']);

        return ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'SELL-V-'.str()->random(4),
            'name' => 'Default',
            'selling_price' => $price,
            'is_default' => true,
        ]);
    });
}

it('lists only the orders a salesperson owns', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'orders.view', DataScope::Company);

    screenOrderFor($f['company'], $f['alice'], 'SO-A');
    screenOrderFor($f['company'], $f['bob'], 'SO-B');

    $response = $this->actingAs($f['alice'])->get('/orders');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Sales/Orders/Index')
        ->has('orders.data', 1)
        ->where('orders.data.0.order_number', 'SO-A'));

    expect($response->getContent())->not->toContain('SO-B');

    $this->actingAs($f['owner'])
        ->get('/orders')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 2));
});

it('refuses the order list to a role without orders.view', function (): void {
    $f = routeFixture();

    $purchaser = person($f['company'], CompanyRole::Purchaser, 'buyer@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $purchaser): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($purchaser->can('orders.view'))->toBeFalse('purchaser must not hold orders.view');
    });

    $this->actingAs($purchaser)->get('/dashboard')->assertOk();
    $this->actingAs($purchaser)->get('/orders')->assertForbidden();
});

it('refuses to open an order outside the data scope', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);

    $bobs = screenOrderFor($f['company'], $f['bob'], 'SO-B');

    $this->actingAs($f['alice'])->get("/orders/{$bobs->getKey()}")->assertForbidden();
});

it('creates an order through the pricing engine and freezes attribution', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);

    $variant = screenVariant($f['company'], '25');

    $this->actingAs($f['alice'])->post('/orders', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '4']],
    ])->assertRedirect();

    $order = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    expect((string) $order->total)->toBe('100.0000')
        ->and($order->owner_user_id)->toBe($f['alice']->getKey())
        ->and($order->fulfilment_status)->toBe(FulfilmentStatus::Draft);

    $this->actingAs($f['alice'])
        ->get("/orders/{$order->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sales/Orders/Show')
            ->has('items', 1)
            ->where('attribution.salesperson', $f['alice']->name)
            ->has('timeline'));
});

it('refuses an order with no lines', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);

    $this->actingAs($f['alice'])->post('/orders', ['lines' => []])->assertSessionHasErrors('lines');

    expect($this->withCompany($f['company'], fn (): int => Order::query()->count()))->toBe(0);
});

it('refuses a direct approve POST from a role that only holds orders.update', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'orders.update', DataScope::Own);

    $order = screenOrderFor($f['company'], $f['alice'], 'SO-APPROVE');

    $this->withCompany($f['company'], function () use ($f, $order): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['alice']->can('orders.approve'))->toBeFalse('salesperson must not hold orders.approve');

        $order->forceFill(['fulfilment_status' => 'pending'])->save();
    });

    $this->actingAs($f['alice'])
        ->post("/orders/{$order->getKey()}/transition", ['axis' => 'fulfilment', 'status' => 'approved'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($order): void {
        expect($order->fresh()?->fulfilment_status)->toBe(FulfilmentStatus::Pending);
    });
});

it('allows a manager holding orders.approve to approve the same order', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'orders.view', DataScope::Company);
    grant($f['company'], CompanyRole::Owner, 'orders.approve', DataScope::Company);
    grant($f['company'], CompanyRole::Owner, 'orders.update', DataScope::Company);

    $order = screenOrderFor($f['company'], $f['alice'], 'SO-APPROVE-2');

    $this->withCompany($f['company'], fn () => $order->forceFill(['fulfilment_status' => 'pending'])->save());

    $response = $this->actingAs($f['owner'])
        ->post("/orders/{$order->getKey()}/transition", ['axis' => 'fulfilment', 'status' => 'approved']);

    $response->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($order): void {
        expect($order->fresh()?->fulfilment_status)->toBe(FulfilmentStatus::Approved);
    });
});

it('refuses a transition the state machine forbids even when the role may act', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'orders.view', DataScope::Company);
    grant($f['company'], CompanyRole::Owner, 'orders.update', DataScope::Company);

    $order = screenOrderFor($f['company'], $f['owner'], 'SO-JUMP');

    $this->actingAs($f['owner'])
        ->post("/orders/{$order->getKey()}/transition", ['axis' => 'fulfilment', 'status' => 'shipped'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($order): void {
        expect($order->fresh()?->fulfilment_status)->toBe(FulfilmentStatus::Draft);
    });
});

it('records a payment and lets the payment status follow the money', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'orders.view', DataScope::Company);
    grant($f['company'], CompanyRole::Owner, 'payments.create', DataScope::Company);

    $order = screenOrderFor($f['company'], $f['owner'], 'SO-PAY', '200');

    $this->actingAs($f['owner'])
        ->post("/orders/{$order->getKey()}/payments", ['amount' => '80', 'method' => 'cash'])
        ->assertRedirect();

    $this->withCompany($f['company'], function () use ($order): void {
        expect($order->fresh()?->payment_status->value)->toBe('partially_paid');
    });

    $this->actingAs($f['owner'])
        ->post("/orders/{$order->getKey()}/payments", ['amount' => '120', 'method' => 'cash'])
        ->assertRedirect();

    $this->withCompany($f['company'], function () use ($order): void {
        expect($order->fresh()?->payment_status->value)->toBe('paid')
            ->and((string) $order->fresh()?->paid_amount)->toBe('200.0000');
    });
});

it('refuses a payment from a role without payments.create', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);

    $order = screenOrderFor($f['company'], $f['alice'], 'SO-NOPAY', '200');

    $this->actingAs($f['alice'])
        ->post("/orders/{$order->getKey()}/payments", ['amount' => '50', 'method' => 'cash'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($order): void {
        expect((string) $order->fresh()?->paid_amount)->toBe('0.0000');
    });
});

it('offers a salesperson no approve transition in the payload it renders', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'orders.update', DataScope::Own);

    $order = screenOrderFor($f['company'], $f['alice'], 'SO-UI');

    $this->actingAs($f['alice'])
        ->get("/orders/{$order->getKey()}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissions.approve', false)
            ->where('permissions.cancel', false)
            ->where('permissions.update', true));
});

it('refuses a direct cancel POST from a role that only holds orders.update', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'orders.update', DataScope::Own);

    $order = screenOrderFor($f['company'], $f['alice'], 'SO-CANCEL');

    $this->withCompany($f['company'], function () use ($f): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['alice']->can('orders.cancel'))->toBeFalse('salesperson must not hold orders.cancel');
    });

    $this->actingAs($f['alice'])
        ->post("/orders/{$order->getKey()}/transition", ['axis' => 'exception', 'status' => 'cancelled'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($order): void {
        expect($order->fresh()?->exception_status->value)->toBe('none');
    });
});

it('lets a role holding orders.cancel cancel the same order', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'orders.view', DataScope::Company);
    grant($f['company'], CompanyRole::Owner, 'orders.cancel', DataScope::Company);

    $order = screenOrderFor($f['company'], $f['alice'], 'SO-CANCEL-2');

    $this->actingAs($f['owner'])
        ->post("/orders/{$order->getKey()}/transition", ['axis' => 'exception', 'status' => 'cancelled'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($order): void {
        expect($order->fresh()?->exception_status->value)->toBe('cancelled');
    });
});
