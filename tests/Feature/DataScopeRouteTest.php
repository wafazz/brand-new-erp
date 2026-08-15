<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Services\Access\RoleProvisioner;
use App\Support\CompanyContext;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

function grant(Company $company, CompanyRole $role, string $permission, DataScope $scope): void
{
    app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $role, $permission, $scope): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $roleModel = Role::query()->where('name', $role->value)->firstOrFail();
        $permissionModel = Permission::query()->where('name', $permission)->firstOrFail();
        $roleModel->givePermissionTo($permissionModel);

        RolePermissionScope::query()->updateOrCreate(
            ['role_id' => $roleModel->getKey(), 'permission_id' => $permissionModel->getKey()],
            ['scope' => $scope]
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });
}

function person(Company $company, CompanyRole $role, string $email, ?Branch $branch = null): User
{
    $user = User::create(['name' => 'P '.str()->random(4), 'email' => $email, 'password' => 'secret-password']);

    app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $user, $role, $branch): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        CompanyUser::create([
            'user_id' => $user->getKey(),
            'role' => $role->value,
            'branch_id' => $branch?->getKey(),
            'is_active' => true,
        ]);

        if ($branch !== null) {
            $branch->users()->attach($user->getKey(), ['id' => (string) str()->ulid(), 'company_id' => $company->getKey()]);
        }

        $user->assignRole($role->value);
    });

    $user->forceFill(['active_company_id' => $company->getKey()])->save();

    return $user->refresh();
}

/** @return array<string, mixed> */
function routeFixture(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($company);

    $branch = app(CompanyContext::class)->runAs(
        $company->getKey(),
        fn (): Branch => Branch::create(['code' => 'HQ', 'name' => 'Head Office', 'is_default' => true])
    );

    $alice = person($company, CompanyRole::Salesperson, 'alice@acme.test', $branch);
    $bob = person($company, CompanyRole::Salesperson, 'bob@acme.test', $branch);
    $owner = person($company, CompanyRole::Owner, 'owner@acme.test', $branch);

    grant($company, CompanyRole::Salesperson, 'audit.view', DataScope::Own);
    grant($company, CompanyRole::Owner, 'audit.view', DataScope::Company);

    $entries = app(CompanyContext::class)->runAs($company->getKey(), fn (): array => [
        'alice' => AuditLog::create(['actor_user_id' => $alice->getKey(), 'action' => 'created', 'module' => 'orders']),
        'bob' => AuditLog::create(['actor_user_id' => $bob->getKey(), 'action' => 'created', 'module' => 'orders']),
        'owner' => AuditLog::create(['actor_user_id' => $owner->getKey(), 'action' => 'created', 'module' => 'orders']),
    ]);

    return compact('company', 'branch', 'alice', 'bob', 'owner', 'entries');
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('shows a salesperson only their own audit entries over HTTP', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])
        ->get('/admin/audit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/AuditLogs/Index')
            ->has('entries', 1)
            ->where('entries.0.id', $f['entries']['alice']->getKey())
        );
});

it('shows an owner every audit entry over HTTP', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->get('/admin/audit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('entries', 3));
});

it('never leaks another salesperson\'s entry id in the payload', function (): void {
    $f = routeFixture();

    $response = $this->actingAs($f['alice'])->get('/admin/audit');
    $body = $response->getContent();

    expect($body)->not->toContain($f['entries']['bob']->getKey());
    expect($body)->not->toContain($f['entries']['owner']->getKey());
});

it('refuses the branches screen to a salesperson who lacks the permission', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])->get('/admin/branches')->assertForbidden();
});

it('allows the branches screen to an owner', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->get('/admin/branches')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Branches/Index')->has('branches', 1));
});

it('refuses a salesperson creating a branch', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])
        ->post('/admin/branches', ['code' => 'NEW', 'name' => 'Sneaky Branch'])
        ->assertForbidden();

    expect($this->withCompany($f['company'], fn (): int => Branch::query()->count()))->toBe(1);
});

it('lets an owner create a branch and writes an audit entry', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->post('/admin/branches', ['code' => 'NTH', 'name' => 'North Branch', 'is_active' => true])
        ->assertRedirect();

    $this->withCompany($f['company'], function (): void {
        expect(Branch::query()->count())->toBe(2)
            ->and(AuditLog::query()->where('module', 'branches')->where('action', 'created')->count())->toBe(1);
    });
});

it('refuses to delete the default branch even for an owner', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->delete('/admin/branches/'.$f['branch']->getKey())
        ->assertForbidden();
});

it('redirects a guest to the login screen', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('records an audit entry on login', function (): void {
    $f = routeFixture();

    $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'secret-password'])
        ->assertRedirect('/dashboard');

    $this->withCompany($f['company'], function (): void {
        expect(AuditLog::query()->where('action', 'logged_in')->count())->toBe(1);
    });
});

it('redacts sensitive attributes from audit payloads', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->post('/admin/branches', ['code' => 'STH', 'name' => 'South Branch'])
        ->assertRedirect();

    $this->withCompany($f['company'], function (): void {
        $entry = AuditLog::query()->where('module', 'branches')->firstOrFail();
        $encoded = json_encode($entry->new_values);

        expect($encoded)->not->toContain('secret-password');
    });
});

it('renders the dashboard with figures limited to the user scope', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'reports.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('figures.variant', 'salesperson')
            ->where('figures.revenue', '0.0000')
            ->has('availableVariants')
        );
});

it('offers an owner more dashboard variants than a salesperson', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'reports.view', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'reports.view', DataScope::Company);

    $this->actingAs($f['owner'])
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('availableVariants', ['management', 'sales', 'marketing']));

    $this->actingAs($f['alice'])
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('availableVariants', ['salesperson']));
});

it('refuses a dashboard variant the role is not entitled to', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'reports.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->get('/dashboard?view=management')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('figures.variant', 'salesperson'));
});
