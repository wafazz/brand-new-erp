<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /** @var array<int, array{key: string, name: string, icon: string, route: string, nav_group: string, is_core: bool}> */
    private const SHIPPED = [
        ['key' => 'dashboard', 'name' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'dashboard', 'nav_group' => 'Overview', 'is_core' => true],
        ['key' => 'companies', 'name' => 'Company', 'icon' => 'bi-building', 'route' => 'companies.edit', 'nav_group' => 'Administration', 'is_core' => true],
        ['key' => 'branches', 'name' => 'Branches', 'icon' => 'bi-shop', 'route' => 'branches.index', 'nav_group' => 'Administration', 'is_core' => true],
        ['key' => 'users', 'name' => 'Users', 'icon' => 'bi-people', 'route' => 'users.index', 'nav_group' => 'Administration', 'is_core' => true],
        ['key' => 'access', 'name' => 'Roles & Access', 'icon' => 'bi-shield-lock', 'route' => 'roles.index', 'nav_group' => 'Administration', 'is_core' => true],
        ['key' => 'audit', 'name' => 'Audit Log', 'icon' => 'bi-clock-history', 'route' => 'audit.index', 'nav_group' => 'Administration', 'is_core' => true],
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
