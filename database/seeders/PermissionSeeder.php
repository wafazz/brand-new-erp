<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionRegistry::all() as $name) {
            $parts = PermissionRegistry::split($name);

            Permission::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['group' => $parts['group'], 'ability' => $parts['ability']]
            );
        }
    }
}
