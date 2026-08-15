<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CompanyRole;
use App\Enums\DataScope;

class PermissionRegistry
{
    /** @var array<string, array<int, string>> */
    private const GROUPS = [
        'companies' => ['view', 'update'],
        'branches' => ['view', 'create', 'update', 'delete'],
        'departments' => ['view', 'create', 'update', 'delete'],
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'create', 'update', 'delete'],
        'modules' => ['view', 'manage'],
        'audit' => ['view', 'export'],
    ];

    /** @var array<string, array<int, string>> */
    private const ROLE_GRANTS = [
        'owner' => ['*'],
        'admin' => ['companies.view', 'branches.*', 'departments.*', 'users.*', 'roles.*', 'modules.*', 'audit.view'],
        'branch_manager' => ['companies.view', 'branches.view', 'departments.view', 'users.view'],
        'sales_manager' => ['companies.view', 'branches.view', 'users.view'],
        'salesperson' => ['companies.view'],
        'marketer' => ['companies.view'],
        'marketing_manager' => ['companies.view', 'users.view'],
        'purchaser' => ['companies.view', 'branches.view'],
        'storekeeper' => ['companies.view', 'branches.view'],
        'accountant' => ['companies.view', 'branches.view', 'audit.view'],
        'staff' => ['companies.view'],
    ];

    /** @var array<string, string> */
    private const DEFAULT_SCOPES = [
        'owner' => 'company',
        'admin' => 'company',
        'branch_manager' => 'branch',
        'sales_manager' => 'team',
        'salesperson' => 'own',
        'marketer' => 'own',
        'marketing_manager' => 'team',
        'purchaser' => 'company',
        'storekeeper' => 'branch',
        'accountant' => 'company',
        'staff' => 'own',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::GROUPS as $group => $abilities) {
            foreach ($abilities as $ability) {
                $permissions[] = "{$group}.{$ability}";
            }
        }

        return $permissions;
    }

    /** @return array{group: string, ability: string} */
    public static function split(string $permission): array
    {
        [$group, $ability] = explode('.', $permission, 2);

        return ['group' => $group, 'ability' => $ability];
    }

    /** @return array<int, string> */
    public static function forRole(CompanyRole $role): array
    {
        $grants = self::ROLE_GRANTS[$role->value];
        $all = self::all();

        if (in_array('*', $grants, true)) {
            return $all;
        }

        $resolved = [];

        foreach ($grants as $grant) {
            if (! str_ends_with($grant, '.*')) {
                $resolved[] = $grant;

                continue;
            }

            $prefix = substr($grant, 0, -1);

            foreach ($all as $permission) {
                if (str_starts_with($permission, $prefix)) {
                    $resolved[] = $permission;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    public static function defaultScopeFor(CompanyRole $role): DataScope
    {
        return DataScope::from(self::DEFAULT_SCOPES[$role->value]);
    }
}
