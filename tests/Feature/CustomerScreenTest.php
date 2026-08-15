<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\AuditLog;
use App\Models\CompanyModuleSetting;
use App\Models\Customer;
use App\Models\Module;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

it('lists only the customers a salesperson owns', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'customers.view', DataScope::Company);

    $this->withCompany($f['company'], function () use ($f): void {
        Customer::create(['code' => 'C1', 'name' => 'Alice Customer', 'owner_user_id' => $f['alice']->getKey()]);
        Customer::create(['code' => 'C2', 'name' => 'Bob Customer', 'owner_user_id' => $f['bob']->getKey()]);
    });

    $this->actingAs($f['alice'])
        ->get('/customers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sales/Customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Alice Customer')
        );

    $this->actingAs($f['owner'])
        ->get('/customers')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('customers.data', 2));
});

it('refuses the customer list to a role without the permission', function (): void {
    $f = routeFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $storekeeper): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($storekeeper->can('customers.view'))->toBeFalse('storekeeper must not hold customers.view');
    });

    $this->actingAs($storekeeper)->get('/dashboard')->assertOk();
    $this->actingAs($storekeeper)->get('/customers')->assertForbidden();
});

it('refuses to open another salesperson\'s customer', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'customers.update', DataScope::Own);

    $bobs = $this->withCompany($f['company'], fn () => Customer::create([
        'code' => 'C2', 'name' => 'Bob Customer', 'owner_user_id' => $f['bob']->getKey(),
    ]));

    $this->actingAs($f['alice'])->get("/customers/{$bobs->getKey()}")->assertForbidden();
    $this->actingAs($f['alice'])->get("/customers/{$bobs->getKey()}/edit")->assertForbidden();
    $this->actingAs($f['alice'])->put("/customers/{$bobs->getKey()}", [
        'name' => 'Hijacked', 'type' => 'individual', 'status' => 'active',
        'credit_limit' => '0', 'payment_terms_days' => 0,
    ])->assertForbidden();

    $this->withCompany($f['company'], function () use ($bobs): void {
        expect($bobs->fresh()?->name)->toBe('Bob Customer');
    });
});

it('creates a customer and records the owner and an audit entry', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'customers.create', DataScope::Own);

    $this->actingAs($f['alice'])->post('/customers', [
        'name' => 'New Buyer',
        'type' => 'individual',
        'status' => 'active',
        'credit_limit' => '500',
        'payment_terms_days' => 14,
    ])->assertRedirect();

    $this->withCompany($f['company'], function () use ($f): void {
        $customer = Customer::query()->where('name', 'New Buyer')->firstOrFail();

        expect($customer->owner_user_id)->toBe($f['alice']->getKey())
            ->and($customer->code)->toStartWith('CU-')
            ->and(AuditLog::query()->where('module', 'customers')->where('action', 'created')->count())->toBe(1);
    });
});

it('rejects an invalid customer rather than saving it', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'customers.create', DataScope::Company);

    $this->actingAs($f['owner'])
        ->post('/customers', ['name' => '', 'type' => 'alien', 'status' => 'active', 'credit_limit' => '-5', 'payment_terms_days' => 14])
        ->assertSessionHasErrors(['name', 'type', 'credit_limit']);

    expect($this->withCompany($f['company'], fn (): int => Customer::query()->count()))->toBe(0);
});

it('shows only the navigation entries the role can reach', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->get('/customers')
        ->assertInertia(function (AssertableInertia $page): void {
            $groups = collect($page->toArray()['props']['navigation']);
            $keys = $groups->flatMap(fn (array $g): array => array_column($g['items'], 'key'));

            expect($keys)->toContain('customers')
                ->and($keys)->not->toContain('branches');
        });
});

it('hides a module from navigation when the company disables it', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'customers.view', DataScope::Company);

    $this->withCompany($f['company'], function (): void {
        CompanyModuleSetting::create(['module_key' => 'customers', 'enabled' => false]);
    });

    $this->actingAs($f['owner'])
        ->get('/dashboard')
        ->assertInertia(function (AssertableInertia $page): void {
            $keys = collect($page->toArray()['props']['navigation'])
                ->flatMap(fn (array $g): array => array_column($g['items'], 'key'));

            expect($keys)->not->toContain('customers');
        });
});

it('never offers a nav entry whose route does not exist', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'customers.view', DataScope::Company);

    $this->withCompany($f['company'], function (): void {
        Module::query()->updateOrCreate(
            ['key' => 'ghost'],
            ['name' => 'Ghost', 'route' => 'nowhere.index', 'nav_group' => 'Sales', 'is_active' => true, 'sort' => 999]
        );
    });

    $this->actingAs($f['owner'])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $keys = collect($page->toArray()['props']['navigation'])
                ->flatMap(fn (array $g): array => array_column($g['items'], 'key'));

            expect($keys)->not->toContain('ghost');
        });
});
