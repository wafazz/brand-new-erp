<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

it('creates a company, a default branch and warehouse, and a signed-in-capable owner', function (): void {
    $this->artisan('erp:create-owner', ['--company' => 'Acme Sdn Bhd', '--name' => 'Fakrul', '--email' => 'owner@acme.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->assertSuccessful();

    $company = Company::query()->where('name', 'Acme Sdn Bhd')->firstOrFail();
    $user = User::query()->where('email', 'owner@acme.test')->firstOrFail();

    expect($user->active_company_id)->toBe($company->getKey())
        ->and(Hash::check('a-long-enough-password', $user->password))->toBeTrue();

    $this->withCompany($company, function () use ($company, $user): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        expect(Branch::query()->where('is_default', true)->count())->toBe(1)
            ->and(Warehouse::query()->where('is_default', true)->count())->toBe(1)
            ->and(CompanyUser::query()->where('user_id', $user->getKey())->value('role'))->toBe(CompanyRole::Owner)
            ->and($user->fresh()->hasRole('owner'))->toBeTrue()
            ->and($user->fresh()->can('purchasing.approve'))->toBeTrue();
    });
});

it('lets the created owner sign in and reach the dashboard', function (): void {
    $this->artisan('erp:create-owner', ['--company' => 'Acme Sdn Bhd', '--name' => 'Fakrul', '--email' => 'owner@acme.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-long-enough-password')
        ->assertSuccessful();

    $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'a-long-enough-password'])
        ->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertOk();
});

it('refuses a password shorter than twelve characters', function (): void {
    $this->artisan('erp:create-owner', ['--company' => 'Acme', '--name' => 'A', '--email' => 'a@acme.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0);
});

it('refuses when the two passwords do not match', function (): void {
    $this->artisan('erp:create-owner', ['--company' => 'Acme', '--name' => 'A', '--email' => 'a@acme.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
        ->expectsQuestion('Confirm password', 'a-different-password')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('refuses to reuse an email that already has an account', function (): void {
    User::create(['name' => 'Existing', 'email' => 'owner@acme.test', 'password' => 'a-long-enough-password']);

    $this->artisan('erp:create-owner', ['--company' => 'Acme', '--name' => 'A', '--email' => 'owner@acme.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});
