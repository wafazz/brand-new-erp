<?php

declare(strict_types=1);

use App\Domain\Hr\LeaveRefused;
use App\Domain\Hr\LeaveService;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\CompanyUser;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

/** @return array<string, mixed> */
function leaveFixture(): array
{
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'leave.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'leave.request', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'leave.view', DataScope::Company);
    grant($f['company'], CompanyRole::Owner, 'leave.approve', DataScope::Company);

    $extra = test()->withCompany($f['company'], function () use ($f): array {
        CompanyUser::query()->where('user_id', $f['alice']->getKey())->update(['manager_id' => $f['owner']->getKey()]);

        return [
            'annual' => LeaveType::create(['code' => 'AL', 'name' => 'Annual leave', 'days_per_year' => '14']),
            'unpaid' => LeaveType::create(['code' => 'UL', 'name' => 'Unpaid leave', 'days_per_year' => '0', 'is_paid' => false]),
        ];
    });

    return [...$f, ...$extra];
}

function leave(): LeaveService
{
    return app(LeaveService::class);
}

it('counts working days and ignores the weekend', function (): void {
    $monday = now()->parse('2026-09-07')->toImmutable();

    expect(leave()->workingDaysBetween($monday, $monday->addDays(4)))->toBe('5.00')
        ->and(leave()->workingDaysBetween($monday, $monday->addDays(6)))->toBe('5.00', 'the weekend adds nothing')
        ->and(leave()->workingDaysBetween($monday->addDays(5), $monday->addDays(6)))->toBe('0.00');
});

it('asks for leave and takes it off the balance immediately', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();

        $request = leave()->request($f['alice'], $f['annual'], $from, $from->addDays(2), 'Family trip');

        expect($request->reference)->toStartWith('LV-')
            ->and((string) $request->days)->toBe('3.00')
            ->and($request->status)->toBe('pending')
            ->and(leave()->remainingFor($f['alice'], $f['annual'], (int) $from->format('Y')))
            ->toBe('11.00', 'a pending request already holds the days, so two requests cannot both fit');
    });
});

it('refuses more leave than remains', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();

        expect(fn () => leave()->request($f['alice'], $f['annual'], $from, $from->addDays(29), 'A long holiday'))
            ->toThrow(LeaveRefused::class, 'only 14.00 remain');

        expect(LeaveRequest::query()->count())->toBe(0);
    });
});

it('allows unlimited leave for a type with no annual entitlement', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();

        $request = leave()->request($f['alice'], $f['unpaid'], $from, $from->addDays(29), 'Sabbatical');

        expect((string) $request->days)->toBe('22.00');
    });
});

it('refuses leave that overlaps a request already in flight', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();

        leave()->request($f['alice'], $f['annual'], $from, $from->addDays(2), 'First');

        expect(fn () => leave()->request($f['alice'], $f['annual'], $from->addDay(), $from->addDays(3), 'Overlapping'))
            ->toThrow(LeaveRefused::class, 'overlaps');

        expect(LeaveRequest::query()->count())->toBe(1);
    });
});

it('refuses dates that are all weekend', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $saturday = now()->addWeek()->next('Saturday')->toImmutable();

        expect(fn () => leave()->request($f['alice'], $f['annual'], $saturday, $saturday->addDay(), 'Weekend'))
            ->toThrow(LeaveRefused::class, 'all weekend');
    });
});

it('refuses a request with no reason', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();

        expect(fn () => leave()->request($f['alice'], $f['annual'], $from, $from, '  '))
            ->toThrow(LeaveRefused::class, 'Say why');
    });
});

it('refuses somebody deciding on their own leave', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();

        $request = leave()->request($f['owner'], $f['annual'], $from, $from, 'Mine');

        expect(fn () => leave()->decide($request, $f['owner'], 'approved'))
            ->toThrow(LeaveRefused::class, 'your own leave');
    });
});

it('refuses a rejection with no note', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();
        $request = leave()->request($f['alice'], $f['annual'], $from, $from, 'Errand');

        expect(fn () => leave()->decide($request, $f['owner'], 'rejected', '  '))
            ->toThrow(LeaveRefused::class, 'needs a reason');

        expect($request->fresh()?->status)->toBe('pending');
    });
});

it('gives the days back when leave is rejected', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();
        $year = (int) $from->format('Y');

        $request = leave()->request($f['alice'], $f['annual'], $from, $from->addDays(2), 'Trip');

        expect(leave()->remainingFor($f['alice'], $f['annual'], $year))->toBe('11.00');

        leave()->decide($request, $f['owner'], 'rejected', 'Too busy that week');

        expect(leave()->remainingFor($f['alice'], $f['annual'], $year))
            ->toBe('14.00', 'refused leave must not keep holding the days');
    });
});

it('refuses to decide twice', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();
        $request = leave()->request($f['alice'], $f['annual'], $from, $from, 'Errand');

        leave()->decide($request, $f['owner'], 'approved');

        expect(fn () => leave()->decide($request->refresh(), $f['owner'], 'rejected', 'Changed my mind'))
            ->toThrow(LeaveRefused::class, 'already been approved');
    });
});

it('lets only the manager decide, not any colleague', function (): void {
    $f = leaveFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'boss@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::SalesManager, 'leave.approve', DataScope::Company);

    $this->withCompany($f['company'], function () use ($f, $manager): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();
        $request = leave()->request($f['alice'], $f['annual'], $from, $from, 'Errand');

        expect(leave()->mayDecideFor($f['owner'], $request))->toBeTrue('the owner is her manager')
            ->and(leave()->mayDecideFor($manager, $request))->toBeFalse('a manager of somebody else is not her manager')
            ->and(leave()->mayDecideFor($f['bob'], $request))->toBeFalse('a colleague certainly is not');
    });
});

it('lets the person withdraw leave that has not started', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();
        $request = leave()->request($f['alice'], $f['annual'], $from, $from->addDays(2), 'Trip');

        leave()->decide($request, $f['owner'], 'approved');

        $cancelled = leave()->cancel($request->refresh(), $f['alice']);

        expect($cancelled->status)->toBe('cancelled')
            ->and(leave()->remainingFor($f['alice'], $f['annual'], (int) $from->format('Y')))->toBe('14.00');
    });
});

it('refuses somebody withdrawing another person leave', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();
        $request = leave()->request($f['alice'], $f['annual'], $from, $from, 'Errand');

        expect(fn () => leave()->cancel($request, $f['bob']))
            ->toThrow(LeaveRefused::class, 'Only the person who asked');
    });
});

it('refuses the leave screen to nobody, because everyone can see their own', function (): void {
    $f = leaveFixture();

    $this->actingAs($f['alice'])
        ->get('/leave')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Hr/Leave')
            ->has('balances', 2)
            ->where('balances.0.remaining', '14.00')
            ->where('can.approve', false));
});

it('refuses leave type setup to somebody who may only request', function (): void {
    $f = leaveFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['alice']->can('leave.request'))->toBeTrue('she can ask for leave')
            ->and($f['alice']->can('leave.configure'))->toBeFalse('but not invent new kinds of it');
    });

    $this->actingAs($f['alice'])->get('/leave-types')->assertForbidden();

    $this->actingAs($f['alice'])
        ->post('/leave-types', ['code' => 'X', 'name' => 'Unlimited', 'days_per_year' => 365, 'is_paid' => true, 'requires_document' => false, 'is_active' => true])
        ->assertForbidden();

    $this->withCompany($f['company'], function (): void {
        expect(LeaveType::query()->count())->toBe(2);
    });
});

it('shows a manager only the requests they may decide', function (): void {
    $f = leaveFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'boss2@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::SalesManager, 'leave.view', DataScope::Company);
    grant($f['company'], CompanyRole::SalesManager, 'leave.approve', DataScope::Company);

    $this->withCompany($f['company'], function () use ($f): void {
        $from = now()->addWeek()->next('Monday')->toImmutable();
        leave()->request($f['alice'], $f['annual'], $from, $from, 'Errand');
    });

    $this->actingAs($f['owner'])
        ->get('/leave')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('awaitingMe', 1));

    $this->actingAs($manager)
        ->get('/leave')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('awaitingMe', 0));
});

it('refuses a decision over HTTP from somebody who is not the manager', function (): void {
    $f = leaveFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'boss3@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::SalesManager, 'leave.approve', DataScope::Company);

    $request = $this->withCompany($f['company'], function () use ($f): LeaveRequest {
        $from = now()->addWeek()->next('Monday')->toImmutable();

        return leave()->request($f['alice'], $f['annual'], $from, $from, 'Errand');
    });

    $this->actingAs($manager)
        ->post("/leave/{$request->getKey()}/decide", ['decision' => 'approved'])
        ->assertForbidden();

    $this->actingAs($f['owner'])
        ->post("/leave/{$request->getKey()}/decide", ['decision' => 'approved'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($request): void {
        expect($request->fresh()?->status)->toBe('approved');
    });
});
