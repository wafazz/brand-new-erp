<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

function membershipOf(Company $company, User $user): CompanyUser
{
    return test()->withCompany($company, fn (): CompanyUser => CompanyUser::query()
        ->where('user_id', $user->getKey())
        ->firstOrFail());
}

it('refuses the people screen to a role without users.view', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])->get('/dashboard')->assertOk();
    $this->actingAs($f['alice'])->get('/admin/users')->assertForbidden();
});

it('lists company members to an owner', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Index')
            ->has('members.data', 3));
});

it('adds a person with an account and a company membership', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])->post('/admin/users', [
        'name' => 'New Hire',
        'email' => 'hire@acme.test',
        'password' => 'a-long-enough-password',
        'role' => 'salesperson',
        'branch_id' => $f['branch']->getKey(),
    ])->assertRedirect();

    $hire = User::query()->where('email', 'hire@acme.test')->firstOrFail();

    $this->withCompany($f['company'], function () use ($f, $hire): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        $member = CompanyUser::query()->where('user_id', $hire->getKey())->firstOrFail();

        expect($member->role)->toBe(CompanyRole::Salesperson)
            ->and($member->is_active)->toBeTrue()
            ->and($hire->fresh()->hasRole('salesperson'))->toBeTrue()
            ->and($hire->fresh()->can('orders.create'))->toBeTrue()
            ->and($hire->fresh()->can('users.create'))->toBeFalse();
    });
});

it('lets the person it just added sign in', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])->post('/admin/users', [
        'name' => 'New Hire',
        'email' => 'hire@acme.test',
        'password' => 'a-long-enough-password',
        'role' => 'salesperson',
        'branch_id' => $f['branch']->getKey(),
    ])->assertRedirect();

    $this->post('/login', ['email' => 'hire@acme.test', 'password' => 'a-long-enough-password'])
        ->assertRedirect('/dashboard');
});

it('refuses an initial password shorter than twelve characters', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])->post('/admin/users', [
        'name' => 'Weak', 'email' => 'weak@acme.test', 'password' => 'short', 'role' => 'staff',
    ])->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'weak@acme.test')->count())->toBe(0);
});

it('refuses to grant a role carrying permissions the actor does not hold', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'boss@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::SalesManager, 'users.view', DataScope::Company);
    grant($f['company'], CompanyRole::SalesManager, 'users.create', DataScope::Company);

    $this->withCompany($f['company'], function () use ($f, $manager): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($manager->can('users.create'))->toBeTrue('the fixture grants the manager users.create')
            ->and($manager->can('modules.manage'))->toBeFalse('a sales manager must not hold modules.manage');
    });

    $this->actingAs($manager)->post('/admin/users', [
        'name' => 'Escalated', 'email' => 'escalated@acme.test', 'password' => 'a-long-enough-password',
        'role' => 'owner',
    ])->assertRedirect()->assertSessionHas('error');

    expect(User::query()->where('email', 'escalated@acme.test')->count())->toBe(0);
});

it('offers a sales manager no owner role to choose from', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'boss2@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::SalesManager, 'users.view', DataScope::Company);
    grant($f['company'], CompanyRole::SalesManager, 'users.create', DataScope::Company);

    $this->actingAs($manager)
        ->get('/admin/users/create')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $roles = collect($page->toArray()['props']['roles']);

            expect($roles->firstWhere('value', 'owner')['grantable'])->toBeFalse()
                ->and($roles->firstWhere('value', 'salesperson')['grantable'])->toBeTrue();
        });
});

it('refuses to change your own access', function (): void {
    $f = routeFixture();

    $admin = person($f['company'], CompanyRole::Admin, 'selfadmin@acme.test', $f['branch']);
    $membership = membershipOf($f['company'], $admin);

    $this->withCompany($f['company'], function () use ($f, $admin): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($admin->can('users.update'))->toBeTrue('an admin can normally edit people');
    });

    $this->actingAs($admin)
        ->put("/admin/users/{$membership->getKey()}", ['role' => 'staff'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($membership): void {
        expect($membership->fresh()?->role)->toBe(CompanyRole::Admin);
    });
});

it('lets an administrator edit an owner\'s details without touching their role', function (): void {
    $f = routeFixture();

    $secondOwner = person($f['company'], CompanyRole::Owner, 'owner3@acme.test', $f['branch']);
    $admin = person($f['company'], CompanyRole::Admin, 'admin0@acme.test', $f['branch']);
    $ownerMembership = membershipOf($f['company'], $f['owner']);

    $this->actingAs($admin)
        ->put("/admin/users/{$ownerMembership->getKey()}", ['role' => 'owner', 'employee_no' => 'E-001'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($ownerMembership): void {
        expect($ownerMembership->fresh()?->employee_no)->toBe('E-001')
            ->and($ownerMembership->fresh()?->role)->toBe(CompanyRole::Owner);
    });
});

it('refuses to demote the last active owner', function (): void {
    $f = routeFixture();

    $secondAdmin = person($f['company'], CompanyRole::Admin, 'admin@acme.test', $f['branch']);
    $ownerMembership = membershipOf($f['company'], $f['owner']);

    $this->withCompany($f['company'], function () use ($f, $secondAdmin): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($secondAdmin->can('users.update'))->toBeTrue('an admin must be able to edit people');
    });

    $this->actingAs($secondAdmin)
        ->put("/admin/users/{$ownerMembership->getKey()}", ['role' => 'staff'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($ownerMembership): void {
        expect($ownerMembership->fresh()?->role)->toBe(CompanyRole::Owner);
    });
});

it('refuses to deactivate the last active owner', function (): void {
    $f = routeFixture();

    $admin = person($f['company'], CompanyRole::Admin, 'admin2@acme.test', $f['branch']);
    $ownerMembership = membershipOf($f['company'], $f['owner']);

    $this->withCompany($f['company'], function () use ($f, $admin): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($admin->can('modules.manage'))->toBeTrue('the admin must not be blocked by the escalation guard here');
    });

    $this->actingAs($admin)
        ->put("/admin/users/{$ownerMembership->getKey()}", ['role' => 'owner', 'is_active' => false])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($ownerMembership): void {
        expect($ownerMembership->fresh()?->is_active)->toBeTrue();
    });
});

it('allows deactivating an owner once a second active owner exists', function (): void {
    $f = routeFixture();

    person($f['company'], CompanyRole::Owner, 'owner4@acme.test', $f['branch']);
    $admin = person($f['company'], CompanyRole::Admin, 'admin3@acme.test', $f['branch']);
    $ownerMembership = membershipOf($f['company'], $f['owner']);

    $this->actingAs($admin)
        ->put("/admin/users/{$ownerMembership->getKey()}", ['role' => 'owner', 'is_active' => false])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($ownerMembership): void {
        expect($ownerMembership->fresh()?->is_active)->toBeFalse();
    });
});

it('allows demoting an owner once a second owner exists', function (): void {
    $f = routeFixture();

    $secondOwner = person($f['company'], CompanyRole::Owner, 'owner2@acme.test', $f['branch']);
    $firstMembership = membershipOf($f['company'], $f['owner']);

    $this->actingAs($secondOwner)
        ->put("/admin/users/{$firstMembership->getKey()}", ['role' => 'staff'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($firstMembership): void {
        expect($firstMembership->fresh()?->role)->toBe(CompanyRole::Staff);
    });
});

it('refuses the role matrix to a role without roles.view', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])->get('/admin/roles')->assertForbidden();

    $this->actingAs($f['owner'])
        ->get('/admin/roles')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Roles/Index')->has('roles'));
});

it('narrows the reach of a role it does not hold', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Company);

    $role = $this->withCompany($f['company'], fn (): Role => Role::query()->where('name', 'salesperson')->firstOrFail());

    $this->actingAs($f['owner'])
        ->post("/admin/roles/{$role->getKey()}/scope", ['permission' => 'customers.view', 'scope' => 'own'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($role): void {
        $scope = RolePermissionScope::query()
            ->where('role_id', $role->getKey())
            ->whereHas('permission', fn ($q) => $q->where('name', 'customers.view'))
            ->firstOrFail();

        expect($scope->scope)->toBe(DataScope::Own);
    });
});

it('refuses to widen a role past the actor own reach', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'boss3@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::SalesManager, 'roles.view', DataScope::Company);
    grant($f['company'], CompanyRole::SalesManager, 'roles.update', DataScope::Company);
    grant($f['company'], CompanyRole::SalesManager, 'customers.view', DataScope::Team);
    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Own);

    $role = $this->withCompany($f['company'], fn (): Role => Role::query()->where('name', 'salesperson')->firstOrFail());

    $this->actingAs($manager)
        ->post("/admin/roles/{$role->getKey()}/scope", ['permission' => 'customers.view', 'scope' => 'company'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($role): void {
        $scope = RolePermissionScope::query()
            ->where('role_id', $role->getKey())
            ->whereHas('permission', fn ($q) => $q->where('name', 'customers.view'))
            ->firstOrFail();

        expect($scope->scope)->toBe(DataScope::Own, 'the salesperson reach must be untouched');
    });
});

it('refuses to change the reach of a role the actor holds themselves', function (): void {
    $f = routeFixture();

    $role = $this->withCompany($f['company'], fn (): Role => Role::query()->where('name', 'owner')->firstOrFail());

    $this->actingAs($f['owner'])
        ->post("/admin/roles/{$role->getKey()}/scope", ['permission' => 'customers.view', 'scope' => 'own'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('refuses to set a scope on a permission the role does not carry', function (): void {
    $f = routeFixture();

    $role = $this->withCompany($f['company'], fn (): Role => Role::query()->where('name', 'storekeeper')->firstOrFail());

    $this->actingAs($f['owner'])
        ->post("/admin/roles/{$role->getKey()}/scope", ['permission' => 'commissions.pay', 'scope' => 'company'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('lets anyone change their own password and refuses a wrong current one', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])
        ->put('/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($f['alice'])
        ->put('/profile/password', [
            'current_password' => 'secret-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
        ->assertSessionHasNoErrors();

    auth()->logout();

    $this->post('/login', ['email' => 'alice@acme.test', 'password' => 'a-brand-new-password'])
        ->assertRedirect('/dashboard');
});

it('refuses a new password that repeats the current one', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])
        ->put('/profile/password', [
            'current_password' => 'secret-password',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
        ->assertSessionHasErrors('password');
});

it('keeps a tuned data scope when roles are synced for a new release', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Company);

    $role = $this->withCompany($f['company'], fn (): Role => Role::query()->where('name', 'salesperson')->firstOrFail());

    $this->actingAs($f['owner'])
        ->post("/admin/roles/{$role->getKey()}/scope", ['permission' => 'customers.view', 'scope' => 'branch'])
        ->assertSessionMissing('error');

    $this->artisan('erp:sync-roles', ['--company' => $f['company']->getKey()])->assertSuccessful();

    $this->withCompany($f['company'], function () use ($role): void {
        $scope = RolePermissionScope::query()
            ->where('role_id', $role->getKey())
            ->whereHas('permission', fn ($q) => $q->where('name', 'customers.view'))
            ->firstOrFail();

        expect($scope->scope)->toBe(DataScope::Branch, 'a release must not silently undo a tuned reach');
    });
});

it('gives an existing company a permission a new release adds', function (): void {
    $f = routeFixture();

    $role = $this->withCompany($f['company'], fn (): Role => Role::query()->where('name', 'owner')->firstOrFail());

    $this->withCompany($f['company'], function () use ($f, $role): void {
        $permission = Permission::query()->where('name', 'commissions.configure')->firstOrFail();

        $role->revokePermissionTo($permission);
        RolePermissionScope::query()
            ->where('role_id', $role->getKey())
            ->where('permission_id', $permission->getKey())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect($f['owner']->fresh()->can('commissions.configure'))->toBeFalse('the fixture removed it');
    });

    $this->artisan('erp:sync-roles', ['--company' => $f['company']->getKey()])->assertSuccessful();

    $this->withCompany($f['company'], function () use ($f): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['owner']->fresh()->can('commissions.configure'))->toBeTrue('sync must hand out what the release adds');
    });
});
