<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /** @var array<int, array{key: string, name: string, icon: string, route: string, permission: ?string, nav_group: string, is_core: bool}> */
    private const SHIPPED = [
        ['key' => 'dashboard', 'name' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'dashboard', 'permission' => null, 'nav_group' => 'Overview', 'is_core' => true],

        ['key' => 'pos', 'name' => 'Point of sale', 'icon' => 'bi-upc-scan', 'route' => 'pos.index', 'permission' => 'pos.view', 'nav_group' => 'Sales', 'is_core' => false],
        ['key' => 'customers', 'name' => 'Customers', 'icon' => 'bi-people', 'route' => 'customers.index', 'permission' => 'customers.view', 'nav_group' => 'Sales', 'is_core' => false],
        ['key' => 'pipeline', 'name' => 'Pipeline', 'icon' => 'bi-kanban', 'route' => 'pipeline.index', 'permission' => 'leads.view', 'nav_group' => 'Sales', 'is_core' => false],
        ['key' => 'leads', 'name' => 'Leads', 'icon' => 'bi-person-plus', 'route' => 'leads.index', 'permission' => 'leads.view', 'nav_group' => 'Sales', 'is_core' => false],
        ['key' => 'orders', 'name' => 'Orders', 'icon' => 'bi-receipt', 'route' => 'orders.index', 'permission' => 'orders.view', 'nav_group' => 'Sales', 'is_core' => false],
        ['key' => 'invoices', 'name' => 'Invoices', 'icon' => 'bi-file-earmark-text', 'route' => 'invoices.index', 'permission' => 'invoices.view', 'nav_group' => 'Sales', 'is_core' => false],

        ['key' => 'products', 'name' => 'Products', 'icon' => 'bi-box-seam', 'route' => 'products.index', 'permission' => 'products.view', 'nav_group' => 'Catalogue', 'is_core' => false],
        ['key' => 'inventory', 'name' => 'Inventory', 'icon' => 'bi-boxes', 'route' => 'inventory.index', 'permission' => 'inventory.view', 'nav_group' => 'Catalogue', 'is_core' => false],
        ['key' => 'suppliers', 'name' => 'Suppliers', 'icon' => 'bi-truck', 'route' => 'suppliers.index', 'permission' => 'suppliers.view', 'nav_group' => 'Purchasing', 'is_core' => false],
        ['key' => 'purchase_requests', 'name' => 'Purchase requests', 'icon' => 'bi-clipboard-check', 'route' => 'purchase_requests.index', 'permission' => 'purchasing.view', 'nav_group' => 'Purchasing', 'is_core' => false],
        ['key' => 'purchase_orders', 'name' => 'Purchase orders', 'icon' => 'bi-cart-check', 'route' => 'purchase_orders.index', 'permission' => 'purchasing.view', 'nav_group' => 'Purchasing', 'is_core' => false],
        ['key' => 'supplier_bills', 'name' => 'Supplier bills', 'icon' => 'bi-receipt-cutoff', 'route' => 'supplier_bills.index', 'permission' => 'purchasing.view', 'nav_group' => 'Purchasing', 'is_core' => false],
        ['key' => 'approvals', 'name' => 'Approvals', 'icon' => 'bi-check2-square', 'route' => 'approvals.index', 'permission' => 'purchasing.approve', 'nav_group' => 'Purchasing', 'is_core' => false],

        ['key' => 'attribution', 'name' => 'Attribution', 'icon' => 'bi-signpost-split', 'route' => 'attribution.index', 'permission' => 'reports.view', 'nav_group' => 'Marketing', 'is_core' => false],
        ['key' => 'campaigns', 'name' => 'Campaigns', 'icon' => 'bi-megaphone', 'route' => 'campaigns.index', 'permission' => 'marketing.view', 'nav_group' => 'Marketing', 'is_core' => false],
        ['key' => 'channels', 'name' => 'Channels', 'icon' => 'bi-broadcast', 'route' => 'channels.index', 'permission' => 'marketing.view', 'nav_group' => 'Marketing', 'is_core' => false],
        ['key' => 'marketers', 'name' => 'Marketers', 'icon' => 'bi-person-video3', 'route' => 'marketers.index', 'permission' => 'marketing.view', 'nav_group' => 'Marketing', 'is_core' => false],

        ['key' => 'commission', 'name' => 'Commission', 'icon' => 'bi-cash-coin', 'route' => 'commissions.index', 'permission' => 'commissions.view', 'nav_group' => 'Money', 'is_core' => false],
        ['key' => 'commission_plans', 'name' => 'Commission plans', 'icon' => 'bi-diagram-3', 'route' => 'commission_plans.index', 'permission' => 'commissions.configure', 'nav_group' => 'Money', 'is_core' => false],

        ['key' => 'leave', 'name' => 'Leave', 'icon' => 'bi-calendar-check', 'route' => 'leave.index', 'permission' => 'leave.view', 'nav_group' => 'People', 'is_core' => false],

        ['key' => 'branches', 'name' => 'Branches', 'icon' => 'bi-shop', 'route' => 'branches.index', 'permission' => 'branches.view', 'nav_group' => 'Administration', 'is_core' => true],
        ['key' => 'users', 'name' => 'People', 'icon' => 'bi-person-badge', 'route' => 'users.index', 'permission' => 'users.view', 'nav_group' => 'Administration', 'is_core' => true],
        ['key' => 'roles', 'name' => 'Roles and reach', 'icon' => 'bi-shield-lock', 'route' => 'roles.index', 'permission' => 'roles.view', 'nav_group' => 'Administration', 'is_core' => true],
        ['key' => 'audit', 'name' => 'Audit log', 'icon' => 'bi-clock-history', 'route' => 'audit.index', 'permission' => 'audit.view', 'nav_group' => 'Administration', 'is_core' => true],
    ];

    public function run(): void
    {
        foreach (self::SHIPPED as $index => $module) {
            Module::query()->updateOrCreate(
                ['key' => $module['key']],
                [...$module, 'sort' => $index * 10, 'is_active' => true]
            );
        }
    }
}
