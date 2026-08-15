<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
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

function screenStock(Company $company, string $onHand = '10'): Stock
{
    return test()->withCompany($company, function () use ($onHand): Stock {
        $product = Product::create(['sku' => 'ST-'.str()->random(4), 'name' => 'Stocked item']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(), 'sku' => 'ST-V-'.str()->random(4), 'name' => 'Default', 'is_default' => true,
        ]);
        $warehouse = Warehouse::create(['code' => 'W'.str()->random(3), 'name' => 'Main', 'is_default' => true]);

        $stock = Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_variant_id' => $variant->getKey()]);
        $stock->forceFill(['on_hand' => $onHand])->save();

        return $stock->refresh();
    });
}

function screenCommission(Company $company, User $recipient, string $amount = '25'): Commission
{
    return test()->withCompany($company, function () use ($recipient, $amount): Commission {
        $order = Order::create(['order_number' => 'SO-C'.str()->random(4), 'customer_name' => 'Buyer', 'placed_at' => now()]);

        $plan = CommissionPlan::create([
            'code' => 'PL-'.str()->random(4),
            'name' => 'Screen plan',
            'strategy' => 'percentage_of_value',
            'recipient_role' => 'salesperson',
            'is_active' => true,
        ]);

        return Commission::create([
            'commission_plan_id' => $plan->getKey(),
            'order_id' => $order->getKey(),
            'recipient_user_id' => $recipient->getKey(),
            'recipient_role' => 'salesperson',
            'status' => 'pending',
            'period' => now()->format('Y-m'),
            'basis_amount' => '500',
            'rate_type' => 'percent',
            'rate_applied' => '5',
            'amount' => $amount,
            'calc_inputs' => ['basis' => '500', 'rate' => '5', 'note' => 'Fixture accrual'],
        ]);
    });
}

it('refuses the inventory screen to a role without inventory.view', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])->get('/dashboard')->assertOk();
    $this->actingAs($f['alice'])->get('/inventory')->assertForbidden();
});

it('shows stock lines to a storekeeper and lets them adjust with a note', function (): void {
    $f = routeFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store3@acme.test', $f['branch']);
    $stock = screenStock($f['company'], '10');

    $this->actingAs($storekeeper)
        ->get('/inventory')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Inventory/Stock/Index')
            ->has('lines.data', 1)
            ->where('lines.data.0.on_hand', '10.0000')
            ->where('can.adjust', true));

    $this->actingAs($storekeeper)
        ->post("/inventory/{$stock->getKey()}/adjust", ['delta' => '-3', 'reason' => 'damaged', 'note' => 'Crushed in transit'])
        ->assertRedirect();

    $this->withCompany($f['company'], function () use ($stock): void {
        expect((string) $stock->fresh()?->on_hand)->toBe('7.0000')
            ->and(StockMovement::query()->where('stock_id', $stock->getKey())->count())->toBe(1);
    });
});

it('refuses a stock adjustment with no note', function (): void {
    $f = routeFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store4@acme.test', $f['branch']);
    $stock = screenStock($f['company'], '10');

    $this->actingAs($storekeeper)
        ->post("/inventory/{$stock->getKey()}/adjust", ['delta' => '-3', 'reason' => 'damaged', 'note' => ''])
        ->assertSessionHasErrors('note');

    $this->withCompany($f['company'], function () use ($stock): void {
        expect((string) $stock->fresh()?->on_hand)->toBe('10.0000');
    });
});

it('refuses a stock adjustment from a role that may only look', function (): void {
    $f = routeFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books5@acme.test', $f['branch']);
    $stock = screenStock($f['company'], '10');

    grant($f['company'], CompanyRole::Accountant, 'inventory.view', DataScope::Company);

    $this->actingAs($accountant)->get('/inventory')->assertOk();

    $this->actingAs($accountant)
        ->post("/inventory/{$stock->getKey()}/adjust", ['delta' => '-3', 'reason' => 'damaged', 'note' => 'Trying it on'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($stock): void {
        expect((string) $stock->fresh()?->on_hand)->toBe('10.0000');
    });
});

it('shows a salesperson only their own commission', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'commissions.view', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'commissions.view', DataScope::Company);

    screenCommission($f['company'], $f['alice'], '25');
    screenCommission($f['company'], $f['bob'], '99');

    $this->actingAs($f['alice'])
        ->get('/commissions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Finance/Commissions/Index')
            ->has('commissions.data', 1)
            ->where('commissions.data.0.amount', '25.0000')
            ->where('totals.pending', '25.0000'));

    $this->actingAs($f['owner'])
        ->get('/commissions')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('commissions.data', 2)
            ->where('totals.pending', '124.0000'));
});

it('refuses a salesperson approving their own commission', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'commissions.view', DataScope::Own);

    $commission = screenCommission($f['company'], $f['alice']);

    $this->withCompany($f['company'], function () use ($f): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['alice']->can('commissions.approve'))->toBeFalse('salesperson must not hold commissions.approve');
    });

    $this->actingAs($f['alice'])
        ->post("/commissions/{$commission->getKey()}/transition", ['status' => 'approved'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($commission): void {
        expect($commission->fresh()?->status)->toBe('pending');
    });
});

it('refuses marking a commission paid without commissions.pay', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'salesboss@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::SalesManager, 'commissions.view', DataScope::Company);
    grant($f['company'], CompanyRole::SalesManager, 'commissions.approve', DataScope::Company);

    $commission = screenCommission($f['company'], $f['alice']);

    $this->withCompany($f['company'], function () use ($f, $manager): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($manager->can('commissions.approve'))->toBeTrue('the fixture grants approve')
            ->and($manager->can('commissions.pay'))->toBeFalse('a sales manager must not hold commissions.pay');
    });

    $this->actingAs($manager)
        ->post("/commissions/{$commission->getKey()}/transition", ['status' => 'paid'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($commission): void {
        expect($commission->fresh()?->status)->toBe('pending');
    });

    $this->actingAs($manager)
        ->post("/commissions/{$commission->getKey()}/transition", ['status' => 'approved'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($commission): void {
        expect($commission->fresh()?->status)->toBe('approved');
    });
});

it('shows a salesperson only the leads assigned to them', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'leads.view', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'leads.view', DataScope::Company);

    $this->withCompany($f['company'], function () use ($f): void {
        Lead::create(['reference' => 'LD-A', 'name' => 'Alice Lead', 'assigned_to' => $f['alice']->getKey(), 'captured_at' => now()]);
        Lead::create(['reference' => 'LD-B', 'name' => 'Bob Lead', 'assigned_to' => $f['bob']->getKey(), 'captured_at' => now()]);
    });

    $response = $this->actingAs($f['alice'])->get('/leads');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Sales/Leads/Index')
        ->has('leads.data', 1)
        ->where('leads.data.0.name', 'Alice Lead'));

    expect($response->getContent())->not->toContain('Bob Lead');

    $this->actingAs($f['owner'])
        ->get('/leads')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('leads.data', 2));
});

it('captures a lead assigned to the person who captured it', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'leads.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'leads.create', DataScope::Own);

    $this->actingAs($f['alice'])->post('/leads', [
        'name' => 'Walk-in enquiry',
        'status' => 'new',
        'estimated_value' => '1200',
    ])->assertRedirect();

    $this->withCompany($f['company'], function () use ($f): void {
        $lead = Lead::query()->where('name', 'Walk-in enquiry')->firstOrFail();

        expect($lead->assigned_to)->toBe($f['alice']->getKey())
            ->and($lead->reference)->toStartWith('LD-');
    });
});

it('refuses to open a lead assigned to someone else', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'leads.view', DataScope::Own);

    $lead = $this->withCompany($f['company'], fn (): Lead => Lead::create([
        'reference' => 'LD-B', 'name' => 'Bob Lead', 'assigned_to' => $f['bob']->getKey(), 'captured_at' => now(),
    ]));

    $this->actingAs($f['alice'])->get("/leads/{$lead->getKey()}")->assertForbidden();
});

it('refuses the supplier list to a salesperson and allows it to a purchaser', function (): void {
    $f = routeFixture();

    $purchaser = person($f['company'], CompanyRole::Purchaser, 'buyer2@acme.test', $f['branch']);

    $this->withCompany($f['company'], function (): void {
        Supplier::create(['code' => 'SP-1', 'name' => 'Acme Wholesale']);
    });

    $this->actingAs($f['alice'])->get('/suppliers')->assertForbidden();

    $this->actingAs($purchaser)
        ->get('/suppliers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Purchasing/Suppliers/Index')
            ->has('suppliers.data', 1)
            ->where('suppliers.data.0.name', 'Acme Wholesale'));
});

it('never shows a supplier belonging to another company', function (): void {
    $f = routeFixture();

    $purchaser = person($f['company'], CompanyRole::Purchaser, 'buyer3@acme.test', $f['branch']);

    $other = Company::create(['name' => 'Rival', 'slug' => 'rival-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($other);

    $this->withCompany($other, fn () => Supplier::create(['code' => 'SP-X', 'name' => 'Rival Wholesale']));
    $this->withCompany($f['company'], fn () => Supplier::create(['code' => 'SP-1', 'name' => 'Our Wholesale']));

    $response = $this->actingAs($purchaser)->get('/suppliers');

    $response->assertInertia(fn (AssertableInertia $page) => $page->has('suppliers.data', 1));

    expect($response->getContent())->not->toContain('Rival Wholesale');
});

it('creates a supplier and allocates a code', function (): void {
    $f = routeFixture();

    $purchaser = person($f['company'], CompanyRole::Purchaser, 'buyer4@acme.test', $f['branch']);

    $this->actingAs($purchaser)->post('/suppliers', [
        'name' => 'New Supplier',
        'currency' => 'MYR',
        'credit_limit' => '5000',
        'payment_terms_days' => 30,
        'status' => 'active',
    ])->assertRedirect();

    $this->withCompany($f['company'], function (): void {
        $supplier = Supplier::query()->where('name', 'New Supplier')->firstOrFail();

        expect($supplier->code)->toStartWith('SP-');
    });
});
