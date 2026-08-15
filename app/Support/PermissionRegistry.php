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
        'reports' => ['view', 'export'],
        'customers' => ['view', 'create', 'update', 'delete', 'export'],
        'suppliers' => ['view', 'create', 'update', 'delete'],
        'products' => ['view', 'create', 'update', 'delete'],
        'orders' => ['view', 'create', 'update', 'approve', 'cancel'],
        'invoices' => ['view', 'create', 'issue', 'void'],
        'payments' => ['view', 'create'],
        'inventory' => ['view', 'adjust', 'transfer'],
        'purchasing' => ['view', 'create', 'approve', 'receive'],
        'leads' => ['view', 'create', 'update', 'convert'],
        'commissions' => ['view', 'configure', 'approve', 'pay'],
        'marketing' => ['view', 'manage'],
        'pos' => ['view', 'sell', 'manage'],
    ];

    /** @var array<string, array<int, string>> */
    private const ROLE_GRANTS = [
        'owner' => ['*'],
        'admin' => ['reports.*', 'marketing.*', 'pos.*', 'customers.*', 'suppliers.*', 'products.*', 'orders.*', 'invoices.*', 'payments.*', 'inventory.*', 'purchasing.*', 'leads.*', 'commissions.*', 'companies.view', 'branches.*', 'departments.*', 'users.*', 'roles.*', 'modules.*', 'audit.view'],
        'branch_manager' => ['reports.view', 'marketing.view', 'pos.*', 'customers.*', 'products.view', 'orders.*', 'invoices.view', 'inventory.*', 'leads.*', 'companies.view', 'branches.view', 'departments.view', 'users.view'],
        'sales_manager' => ['reports.view', 'marketing.view', 'pos.view', 'pos.sell', 'customers.*', 'products.view', 'orders.*', 'invoices.view', 'leads.*', 'commissions.view', 'companies.view', 'branches.view', 'users.view'],
        'salesperson' => ['reports.view', 'pos.view', 'pos.sell', 'companies.view', 'customers.view', 'customers.create', 'customers.update', 'products.view', 'orders.view', 'orders.create', 'orders.update', 'invoices.view', 'leads.view', 'leads.create', 'leads.update', 'leads.convert', 'commissions.view'],
        'marketer' => ['reports.view', 'marketing.view', 'companies.view', 'customers.view', 'products.view', 'leads.*', 'commissions.view'],
        'marketing_manager' => ['reports.view', 'marketing.*', 'customers.view', 'products.view', 'leads.*', 'commissions.view', 'companies.view', 'users.view'],
        'purchaser' => ['suppliers.*', 'products.*', 'purchasing.*', 'inventory.view', 'companies.view', 'branches.view'],
        'storekeeper' => ['pos.view', 'pos.sell', 'products.view', 'inventory.*', 'purchasing.receive', 'orders.view', 'companies.view', 'branches.view'],
        'accountant' => ['reports.*', 'pos.view', 'customers.view', 'suppliers.view', 'orders.view', 'invoices.*', 'payments.*', 'commissions.view', 'commissions.approve', 'commissions.pay', 'purchasing.view', 'companies.view', 'branches.view', 'audit.view'],
        'staff' => ['companies.view', 'customers.view', 'products.view', 'orders.view'],
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
