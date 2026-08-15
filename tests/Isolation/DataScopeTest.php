<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Services\Access\RoleProvisioner;
use App\Services\Access\ScopeResolver;
use App\Support\CompanyContext;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

function grantScope(Company $company, CompanyRole $role, string $permission, DataScope $scope): void
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

function staffMember(
    Company $company,
    CompanyRole $role,
    string $email,
    ?Branch $branch = null,
    ?User $manager = null,
): User {
    $user = User::create([
        'name' => 'Staff '.str()->random(4),
        'email' => $email,
        'password' => 'secret-password',
    ]);

    app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $user, $role, $branch, $manager): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        CompanyUser::create([
            'user_id' => $user->getKey(),
            'role' => $role->value,
            'branch_id' => $branch?->getKey(),
            'manager_id' => $manager?->getKey(),
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

function auditEntry(Company $company, ?User $actor, ?Branch $branch = null): AuditLog
{
    return app(CompanyContext::class)->runAs($company->getKey(), fn (): AuditLog => AuditLog::create([
        'actor_user_id' => $actor?->getKey(),
        'branch_id' => $branch?->getKey(),
        'action' => 'updated',
        'module' => 'orders',
    ]));
}

/** @return array<string, mixed> */
function scopeFixture(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);
    $rival = Company::create(['name' => 'Rival Sdn Bhd', 'slug' => 'rival-'.str()->random(6)]);

    app(RoleProvisioner::class)->provision($company);
    app(RoleProvisioner::class)->provision($rival);

    [$north, $south] = app(CompanyContext::class)->runAs($company->getKey(), fn (): array => [
        Branch::create(['code' => 'NTH', 'name' => 'North']),
        Branch::create(['code' => 'STH', 'name' => 'South']),
    ]);

    $manager = staffMember($company, CompanyRole::SalesManager, 'manager@acme.test', $north);
    $alice = staffMember($company, CompanyRole::Salesperson, 'alice@acme.test', $north, $manager);
    $bob = staffMember($company, CompanyRole::Salesperson, 'bob@acme.test', $south);
    $branchManager = staffMember($company, CompanyRole::BranchManager, 'bm@acme.test', $north);
    $owner = staffMember($company, CompanyRole::Owner, 'owner@acme.test', $north);

    $grants = [
        [CompanyRole::Salesperson, DataScope::Own],
        [CompanyRole::SalesManager, DataScope::Team],
        [CompanyRole::BranchManager, DataScope::Branch],
        [CompanyRole::Owner, DataScope::Company],
    ];

    foreach ($grants as [$role, $scope]) {
        grantScope($company, $role, 'audit.view', $scope);
    }

    auditEntry($company, $alice, $north);
    auditEntry($company, $alice, $north);
    auditEntry($company, $bob, $south);
    auditEntry($company, $manager, $north);
    auditEntry($company, $owner, $north);

    $rivalUser = staffMember($rival, CompanyRole::Owner, 'owner@rival.test');
    auditEntry($rival, $rivalUser);

    return compact('company', 'rival', 'north', 'south', 'manager', 'alice', 'bob', 'branchManager', 'owner');
}

function visibleCount(Company $company, User $user): int
{
    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $user): int {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        return AuditLog::query()->visibleTo($user, 'audit.view')->count();
    });
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('resolves the scope recorded against the role', function (): void {
    $f = scopeFixture();

    app(CompanyContext::class)->runAs($f['company']->getKey(), function () use ($f): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());
        $resolver = app(ScopeResolver::class);

        expect($resolver->for($f['alice'], 'audit.view'))->toBe(DataScope::Own)
            ->and($resolver->for($f['manager'], 'audit.view'))->toBe(DataScope::Team)
            ->and($resolver->for($f['branchManager'], 'audit.view'))->toBe(DataScope::Branch)
            ->and($resolver->for($f['owner'], 'audit.view'))->toBe(DataScope::Company);
    });
});

it('shows a salesperson only their own records', function (): void {
    $f = scopeFixture();

    expect(visibleCount($f['company'], $f['alice']))->toBe(2)
        ->and(visibleCount($f['company'], $f['bob']))->toBe(1);
});

it('shows a sales manager their own and their subordinates records', function (): void {
    $f = scopeFixture();

    expect(visibleCount($f['company'], $f['manager']))->toBe(3);
});

it('shows a branch manager every record in their branch', function (): void {
    $f = scopeFixture();

    expect(visibleCount($f['company'], $f['branchManager']))->toBe(4);
});

it('shows an owner every record in the company and none from another', function (): void {
    $f = scopeFixture();

    expect(visibleCount($f['company'], $f['owner']))->toBe(5);
});

it('fails closed when the user holds the permission but no scope row', function (): void {
    $f = scopeFixture();

    app(CompanyContext::class)->runAs($f['company']->getKey(), function () use ($f): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        $role = Role::query()->where('name', CompanyRole::Salesperson->value)->firstOrFail();
        RolePermissionScope::query()->where('role_id', $role->getKey())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });

    expect(visibleCount($f['company'], $f['alice']))->toBe(0);
});

it('fails closed when the user does not hold the permission at all', function (): void {
    $f = scopeFixture();

    $stranger = staffMember($f['company'], CompanyRole::Storekeeper, 'store@acme.test', $f['north']);

    expect(visibleCount($f['company'], $stranger))->toBe(0);
});

it('fails closed for a branch-scoped user who belongs to no branch', function (): void {
    $f = scopeFixture();

    $orphan = staffMember($f['company'], CompanyRole::BranchManager, 'orphan@acme.test');

    expect(visibleCount($f['company'], $orphan))->toBe(0);
});

it('never lets a widened data scope cross the company boundary', function (): void {
    $f = scopeFixture();

    grantScope($f['company'], CompanyRole::Owner, 'audit.view', DataScope::All);

    expect(visibleCount($f['company'], $f['owner']))->toBe(5);
});

it('applies the same scope to an aggregate as to a listing', function (): void {
    $f = scopeFixture();

    $listed = app(CompanyContext::class)->runAs($f['company']->getKey(), function () use ($f): int {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        return AuditLog::query()->visibleTo($f['alice'], 'audit.view')->get()->count();
    });

    expect($listed)->toBe(visibleCount($f['company'], $f['alice']));
});

it('refuses to scope a model that does not implement Scopeable', function (): void {
    $f = scopeFixture();

    app(CompanyContext::class)->runAs($f['company']->getKey(), function () use ($f): void {
        expect(fn () => app(ScopeResolver::class)->apply(Department::query(), $f['alice'], 'audit.view'))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('keeps an audit entry append-only through the model', function (): void {
    $f = scopeFixture();

    app(CompanyContext::class)->runAs($f['company']->getKey(), function (): void {
        $entry = AuditLog::query()->firstOrFail();

        expect(fn () => $entry->update(['action' => 'tampered']))->toThrow(RuntimeException::class)
            ->and(fn () => $entry->delete())->toThrow(RuntimeException::class);
    });
});
