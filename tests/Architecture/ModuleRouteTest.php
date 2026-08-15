<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Module;
use App\Support\PermissionRegistry;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

it('resolves every seeded module route, so no module is silently missing from the sidebar', function (): void {
    $company = Company::create(['name' => 'Nav Check', 'slug' => 'nav-'.str()->random(6)]);

    $broken = $this->withCompany($company, fn (): array => Module::query()
        ->whereNotNull('route')
        ->get()
        ->reject(fn (Module $module): bool => app('router')->has((string) $module->route))
        ->map(fn (Module $module): string => "{$module->key} → {$module->route}")
        ->values()
        ->all());

    expect($broken)->toBe([], 'these modules name a route that does not exist, so NavigationBuilder drops them without a word: '.implode(', ', $broken));
});

it('names a real permission on every module that claims one', function (): void {
    $company = Company::create(['name' => 'Perm Check', 'slug' => 'perm-'.str()->random(6)]);
    $known = PermissionRegistry::all();

    $unknown = $this->withCompany($company, fn (): array => Module::query()
        ->whereNotNull('permission')
        ->get()
        ->reject(fn (Module $module): bool => in_array((string) $module->permission, $known, true))
        ->map(fn (Module $module): string => "{$module->key} → {$module->permission}")
        ->values()
        ->all());

    expect($unknown)->toBe([], 'these modules gate on a permission that is not in the registry: '.implode(', ', $unknown));
});
