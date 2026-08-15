<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\AuditLog;
use App\Models\Branch;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('shows a salesperson only their own audit entries over HTTP', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])
        ->get('/admin/audit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/AuditLogs/Index')
            ->has('entries', 1)
            ->where('entries.0.id', $f['entries']['alice']->getKey())
        );
});

it('shows an owner every audit entry over HTTP', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->get('/admin/audit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('entries', 3));
});

it('never leaks another salesperson\'s entry id in the payload', function (): void {
    $f = routeFixture();

    $response = $this->actingAs($f['alice'])->get('/admin/audit');
    $body = $response->getContent();

    expect($body)->not->toContain($f['entries']['bob']->getKey());
    expect($body)->not->toContain($f['entries']['owner']->getKey());
});

it('refuses the branches screen to a salesperson who lacks the permission', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])->get('/admin/branches')->assertForbidden();
});

it('allows the branches screen to an owner', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->get('/admin/branches')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Branches/Index')->has('branches', 1));
});

it('refuses a salesperson creating a branch', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])
        ->post('/admin/branches', ['code' => 'NEW', 'name' => 'Sneaky Branch'])
        ->assertForbidden();

    expect($this->withCompany($f['company'], fn (): int => Branch::query()->count()))->toBe(1);
});

it('lets an owner create a branch and writes an audit entry', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->post('/admin/branches', ['code' => 'NTH', 'name' => 'North Branch', 'is_active' => true])
        ->assertRedirect();

    $this->withCompany($f['company'], function (): void {
        expect(Branch::query()->count())->toBe(2)
            ->and(AuditLog::query()->where('module', 'branches')->where('action', 'created')->count())->toBe(1);
    });
});

it('refuses to delete the default branch even for an owner', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->delete('/admin/branches/'.$f['branch']->getKey())
        ->assertForbidden();
});

it('redirects a guest to the login screen', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('records an audit entry on login', function (): void {
    $f = routeFixture();

    $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'secret-password'])
        ->assertRedirect('/dashboard');

    $this->withCompany($f['company'], function (): void {
        expect(AuditLog::query()->where('action', 'logged_in')->count())->toBe(1);
    });
});

it('redacts sensitive attributes from audit payloads', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])
        ->post('/admin/branches', ['code' => 'STH', 'name' => 'South Branch'])
        ->assertRedirect();

    $this->withCompany($f['company'], function (): void {
        $entry = AuditLog::query()->where('module', 'branches')->firstOrFail();
        $encoded = json_encode($entry->new_values);

        expect($encoded)->not->toContain('secret-password');
    });
});

it('renders the dashboard with figures limited to the user scope', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'reports.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('figures.variant', 'salesperson')
            ->where('figures.revenue', '0.0000')
            ->has('availableVariants')
        );
});

it('offers an owner more dashboard variants than a salesperson', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'reports.view', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'reports.view', DataScope::Company);

    $this->actingAs($f['owner'])
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('availableVariants', ['management', 'sales', 'marketing']));

    $this->actingAs($f['alice'])
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('availableVariants', ['salesperson']));
});

it('refuses a dashboard variant the role is not entitled to', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'reports.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->get('/dashboard?view=management')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('figures.variant', 'salesperson'));
});
