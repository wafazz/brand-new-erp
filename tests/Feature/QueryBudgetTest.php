<?php

declare(strict_types=1);

use App\Domain\Reporting\RollupService;
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
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

function budgetWorld(int $auditRows): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($company);

    $owner = User::create(['name' => 'Owner', 'email' => 'owner'.str()->random(4).'@a.test', 'password' => 'secret-password']);

    app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $owner, $auditRows): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $branch = Branch::create(['code' => 'HQ', 'name' => 'Head Office', 'is_default' => true]);
        CompanyUser::create(['user_id' => $owner->getKey(), 'role' => 'owner', 'branch_id' => $branch->getKey(), 'is_active' => true]);
        $branch->users()->attach($owner->getKey(), ['id' => (string) str()->ulid(), 'company_id' => $company->getKey()]);
        $owner->assignRole('owner');

        foreach (['audit.view', 'reports.view'] as $permission) {
            $role = Role::query()->where('name', CompanyRole::Owner->value)->firstOrFail();
            $model = Permission::query()->where('name', $permission)->firstOrFail();
            $role->givePermissionTo($model);
            RolePermissionScope::query()->updateOrCreate(
                ['role_id' => $role->getKey(), 'permission_id' => $model->getKey()],
                ['scope' => DataScope::Company]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        for ($i = 0; $i < $auditRows; $i++) {
            AuditLog::create([
                'actor_user_id' => $owner->getKey(),
                'branch_id' => $branch->getKey(),
                'action' => 'updated',
                'module' => 'orders',
            ]);
        }
    });

    $owner->forceFill(['active_company_id' => $company->getKey()])->save();

    return ['company' => $company, 'owner' => $owner->refresh()];
}

function countQueries(callable $callback): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('does not issue a query per row on the audit screen', function (): void {
    $w = budgetWorld(3);

    $this->actingAs($w['owner'])->get('/admin/audit')->assertOk();

    $withFew = countQueries(fn () => $this->actingAs($w['owner'])->get('/admin/audit')->assertOk());

    app(CompanyContext::class)->runAs($w['company']->getKey(), function () use ($w): void {
        for ($i = 0; $i < 27; $i++) {
            AuditLog::create([
                'actor_user_id' => $w['owner']->getKey(),
                'action' => 'updated',
                'module' => 'orders',
            ]);
        }
    });

    $withMany = countQueries(fn () => $this->actingAs($w['owner'])->get('/admin/audit')->assertOk());

    expect($withMany)->toBe(
        $withFew,
        "The audit screen issued {$withFew} queries for 3 rows and {$withMany} for 30. That is an N+1."
    );
});

it('keeps the audit screen inside a fixed query budget', function (): void {
    $w = budgetWorld(25);

    $this->actingAs($w['owner'])->get('/admin/audit')->assertOk();

    $count = countQueries(fn () => $this->actingAs($w['owner'])->get('/admin/audit')->assertOk());

    expect($count)->toBeLessThan(20, "The audit screen used {$count} queries.");
});

it('keeps the dashboard inside a fixed query budget', function (): void {
    $w = budgetWorld(5);

    app(CompanyContext::class)->runAs($w['company']->getKey(), fn () => app(RollupService::class)->rebuildSales(now()));

    $this->actingAs($w['owner'])->get('/dashboard')->assertOk();

    $count = countQueries(fn () => $this->actingAs($w['owner'])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard')));

    expect($count)->toBeLessThan(30, "The dashboard used {$count} queries.");
});
