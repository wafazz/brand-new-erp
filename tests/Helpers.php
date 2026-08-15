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
