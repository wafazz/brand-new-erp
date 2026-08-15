<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class RoleProvisioner
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function provision(Company $company): void
    {
        $this->context->runAs($company->getKey(), function () use ($company): void {
            $this->registrar->setPermissionsTeamId($company->getKey());

            DB::transaction(function () use ($company): void {
                foreach (CompanyRole::cases() as $case) {
                    $role = Role::query()->firstOrCreate([
                        'name' => $case->value,
                        'guard_name' => 'web',
                        'company_id' => $company->getKey(),
                    ], ['is_system' => true]);

                    $names = PermissionRegistry::forRole($case);
                    $permissions = Permission::query()->whereIn('name', $names)->get();

                    $role->syncPermissions($permissions);

                    $scope = PermissionRegistry::defaultScopeFor($case);

                    foreach ($permissions as $permission) {
                        RolePermissionScope::query()->updateOrCreate(
                            ['role_id' => $role->getKey(), 'permission_id' => $permission->getKey()],
                            ['scope' => $scope]
                        );
                    }
                }
            });

            $this->registrar->forgetCachedPermissions();
        });
    }
}
