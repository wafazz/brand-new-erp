<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\RoleProvisioner;
use App\Support\CompanyContext;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;

function member(Company $company, string $role = 'owner', string $email = 'owner@example.test'): User
{
    $user = User::create([
        'name' => 'Member '.str()->random(4),
        'email' => $email,
        'password' => 'secret-password',
    ]);

    app(CompanyContext::class)->runAs($company->getKey(), function () use ($user, $role): void {
        CompanyUser::create([
            'user_id' => $user->getKey(),
            'role' => $role,
            'is_active' => true,
        ]);

        Branch::create(['code' => 'HQ', 'name' => 'Head Office', 'is_default' => true]);
    });

    $user->forceFill(['active_company_id' => $company->getKey()])->save();

    return $user->refresh();
}

it('renders the dashboard for a member of an active company', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);

    $company = $this->company('Acme Trading');
    $user = member($company);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('companyName', 'Acme Trading')
            ->where('branchCount', 1)
            ->where('userCount', 1)
        );
});

it('refuses a user whose active company has no membership', function (): void {
    $company = $this->company('Acme Trading');
    $other = $this->company('Rival Sdn Bhd');
    $user = member($company);

    $user->forceFill(['active_company_id' => $other->getKey()])->save();

    $this->actingAs($user->refresh())->get('/dashboard')->assertForbidden();
});

it('refuses a user with no active company', function (): void {
    $company = $this->company('Acme Trading');
    $user = member($company);

    $user->forceFill(['active_company_id' => null])->save();

    $this->actingAs($user->refresh())->get('/dashboard')->assertForbidden();
});

it('refuses a member of a deactivated company', function (): void {
    $company = $this->company('Acme Trading');
    $user = member($company);

    $company->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

it('rejects a guest before any company is resolved', function (): void {
    $this->get('/dashboard')->assertUnauthorized();

    expect(app(CompanyContext::class)->hasContext())->toBeFalse();
});

it('counts only the resolved company\'s branches on the dashboard', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);

    $mine = $this->company('Acme Trading');
    $theirs = $this->company('Rival Sdn Bhd');

    $user = member($mine, 'owner', 'mine@example.test');
    member($theirs, 'owner', 'theirs@example.test');

    $this->withCompany($theirs, function (): void {
        Branch::create(['code' => 'B2', 'name' => 'Rival Branch Two']);
        Branch::create(['code' => 'B3', 'name' => 'Rival Branch Three']);
    });

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('branchCount', 1));
});

it('provisions every role with its default data scope', function (): void {
    $this->seed(PermissionSeeder::class);

    $company = $this->company('Acme Trading');

    app(RoleProvisioner::class)->provision($company);

    $this->withCompany($company, function (): void {
        $owner = Role::query()->where('name', 'owner')->firstOrFail();
        $salesperson = Role::query()->where('name', 'salesperson')->firstOrFail();

        expect($owner->permissionScopes()->count())->toBeGreaterThan(0)
            ->and($owner->permissionScopes()->first()->scope)->toBe(DataScope::Company)
            ->and($salesperson->permissionScopes()->first()->scope)->toBe(DataScope::Own);
    });
});

it('never leaks roles from another company', function (): void {
    $this->seed(PermissionSeeder::class);

    $mine = $this->company('Acme Trading');
    $theirs = $this->company('Rival Sdn Bhd');

    app(RoleProvisioner::class)->provision($mine);
    app(RoleProvisioner::class)->provision($theirs);

    $visible = $this->withCompany($mine, fn (): int => Role::query()->count());

    expect($visible)->toBe(count(CompanyRole::cases()));
});
