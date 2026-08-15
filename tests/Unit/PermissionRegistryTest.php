<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Support\PermissionRegistry;

it('grants every role a permission set and a default scope', function (CompanyRole $role): void {
    expect(PermissionRegistry::forRole($role))->toBeArray()
        ->and(PermissionRegistry::defaultScopeFor($role))->toBeInstanceOf(DataScope::class);
})->with(CompanyRole::cases());

it('never grants a company role the platform-wide scope', function (CompanyRole $role): void {
    expect(PermissionRegistry::defaultScopeFor($role))->not->toBe(DataScope::All);
})->with(CompanyRole::cases());

it('gives the owner every permission', function (): void {
    expect(PermissionRegistry::forRole(CompanyRole::Owner))->toBe(PermissionRegistry::all());
});

it('expands wildcard grants to concrete permissions', function (): void {
    $admin = PermissionRegistry::forRole(CompanyRole::Admin);

    expect($admin)->toContain('branches.view')
        ->and($admin)->toContain('branches.delete')
        ->and(in_array('branches.*', $admin, true))->toBeFalse();
});

it('names every permission as group.ability', function (string $permission): void {
    expect($permission)->toMatch('/^[a-z_]+\.[a-z_]+$/');
})->with(PermissionRegistry::all());

it('splits a permission into its group and ability', function (): void {
    expect(PermissionRegistry::split('branches.create'))
        ->toBe(['group' => 'branches', 'ability' => 'create']);
});
