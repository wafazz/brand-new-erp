<?php

declare(strict_types=1);

use App\Domain\Attribution\AttributionService;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Reporting\DashboardService;
use App\Domain\Reporting\LiveSalesOracle;
use App\Domain\Reporting\RollupService;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Enums\ExceptionStatus;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Marketer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Models\RollupRun;
use App\Models\SalesRollup;
use App\Models\SalesTarget;
use App\Models\SalesTeam;
use App\Models\SalesTeamMember;
use App\Models\User;
use App\Services\Access\RoleProvisioner;
use App\Support\CompanyContext;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

function reportingWorld(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($company);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $branch = Branch::create(['code' => 'HQ', 'name' => 'Head Office', 'is_default' => true]);
        $channel = Channel::create(['code' => 'FB', 'name' => 'Facebook']);

        $aliUser = User::create(['name' => 'Ali', 'email' => 'ali'.str()->random(4).'@a.test', 'password' => 'secret-password']);
        $ali = Marketer::create(['user_id' => $aliUser->getKey(), 'code' => 'MK-ALI']);
        $campaign = Campaign::create(['channel_id' => $channel->getKey(), 'marketer_id' => $ali->getKey(), 'code' => 'RAYA', 'name' => 'Raya']);

        $team = SalesTeam::create(['code' => 'NORTH', 'name' => 'North', 'branch_id' => $branch->getKey()]);
        SalesTarget::create(['sales_team_id' => $team->getKey(), 'period' => now()->format('Y-m'), 'target_amount' => '1000']);

        $siti = reportingMember($company, 'Siti', CompanyRole::Salesperson, $branch, $team);
        $rahim = reportingMember($company, 'Rahim', CompanyRole::Salesperson, $branch, $team);
        $owner = reportingMember($company, 'Owner', CompanyRole::Owner, $branch, $team);

        $product = Product::create(['sku' => 'W', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'W-STD',
            'selling_price' => '500.0000',
            'cost_price' => '300.0000',
        ]);

        $customer = Customer::create(['code' => 'C1', 'name' => 'Aminah']);
        $lead = Lead::create(['reference' => 'LD-1', 'name' => 'Aminah', 'converted_customer_id' => $customer->getKey(), 'captured_at' => now()]);

        $attribution = app(AttributionService::class);
        $attribution->recordTouch($lead, [
            'channel_id' => $channel->getKey(),
            'campaign_id' => $campaign->getKey(),
            'marketer_id' => $ali->getKey(),
        ]);
        $attribution->resolveFor($customer);

        $service = app(OrderService::class);

        $service->create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_name' => 'Aminah',
            'lead_id' => $lead->getKey(),
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
        ], $siti);

        $service->create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_name' => 'Aminah',
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '1']],
        ], $rahim);

        $grants = [
            [CompanyRole::Salesperson, DataScope::Own],
            [CompanyRole::Owner, DataScope::Company],
        ];

        foreach ($grants as [$role, $scope]) {
            reportingGrant($company, $role, $scope);
        }

        return compact('company', 'branch', 'campaign', 'ali', 'team', 'siti', 'rahim', 'owner', 'variant', 'customer');
    });
}

function reportingMember(Company $company, string $name, CompanyRole $role, Branch $branch, SalesTeam $team): User
{
    $user = User::create(['name' => $name, 'email' => strtolower($name).str()->random(4).'@a.test', 'password' => 'secret-password']);

    CompanyUser::create(['user_id' => $user->getKey(), 'role' => $role->value, 'branch_id' => $branch->getKey(), 'is_active' => true]);
    SalesTeamMember::create(['sales_team_id' => $team->getKey(), 'user_id' => $user->getKey()]);
    $branch->users()->attach($user->getKey(), ['id' => (string) str()->ulid(), 'company_id' => $company->getKey()]);
    $user->assignRole($role->value);
    $user->forceFill(['active_company_id' => $company->getKey()])->save();

    return $user->refresh();
}

function reportingGrant(Company $company, CompanyRole $role, DataScope $scope): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

    $roleModel = Role::query()->where('name', $role->value)->firstOrFail();
    $permission = Permission::query()->where('name', 'reports.view')->firstOrFail();
    $roleModel->givePermissionTo($permission);

    RolePermissionScope::query()->updateOrCreate(
        ['role_id' => $roleModel->getKey(), 'permission_id' => $permission->getKey()],
        ['scope' => $scope]
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function inReporting(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $callback) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        return $callback();
    });
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('builds a rollup that matches the live oracle exactly', function (): void {
    $w = reportingWorld();

    inReporting($w['company'], function (): void {
        app(RollupService::class)->rebuildSales(now());

        $live = app(LiveSalesOracle::class)->forDate(now());
        $stored = SalesRollup::query()->get();

        expect($stored)->toHaveCount($live->count());

        foreach ($stored as $row) {
            $key = implode('|', [
                $row->branch_id ?? '-',
                $row->salesperson_user_id ?? '-',
                $row->sales_team_id ?? '-',
                $row->marketer_id ?? '-',
                $row->campaign_id ?? '-',
                $row->channel_id ?? '-',
            ]);

            $expected = $live->get($key);

            expect($expected)->not->toBeNull("rollup slice {$key} has no live counterpart")
                ->and((string) $row->revenue)->toBe($expected['revenue'])
                ->and((string) $row->cost)->toBe($expected['cost'])
                ->and((string) $row->margin)->toBe($expected['margin'])
                ->and((int) $row->orders_count)->toBe($expected['orders_count']);
        }
    });
});

it('totals the same revenue whether read from the rollup or computed live', function (): void {
    $w = reportingWorld();

    inReporting($w['company'], function (): void {
        app(RollupService::class)->rebuildSales(now());

        $stored = (string) SalesRollup::query()->sum('revenue');
        $live = app(LiveSalesOracle::class)->forDate(now())
            ->reduce(fn (string $carry, array $slice): string => bcadd($carry, $slice['revenue'], 4), '0');

        expect(bccomp($stored, $live, 4))->toBe(0)
            ->and($live)->toBe('1500.0000');
    });
});

it('does not double count when the rollup is rebuilt', function (): void {
    $w = reportingWorld();

    inReporting($w['company'], function (): void {
        $service = app(RollupService::class);
        $service->rebuildSales(now());
        $first = (string) SalesRollup::query()->sum('revenue');

        $service->rebuildSales(now());
        $service->rebuildSales(now());

        expect((string) SalesRollup::query()->sum('revenue'))->toBe($first);
    });
});

it('drops a cancelled order out of the rollup on rebuild', function (): void {
    $w = reportingWorld();

    inReporting($w['company'], function (): void {
        $service = app(RollupService::class);
        $service->rebuildSales(now());

        expect((string) SalesRollup::query()->sum('revenue'))->toBe('1500.0000');

        $order = Order::query()->orderBy('order_number')->firstOrFail();
        app(OrderStateMachine::class)->transition($order, ExceptionStatus::Cancelled);

        $service->rebuildSales(now());

        expect((string) SalesRollup::query()->sum('revenue'))->toBe('500.0000');
    });
});

it('shows a salesperson only their own revenue on the dashboard', function (): void {
    $w = reportingWorld();

    [$siti, $rahim, $owner] = inReporting($w['company'], function () use ($w): array {
        app(RollupService::class)->rebuildSales(now());
        $dashboard = app(DashboardService::class);
        $period = now()->format('Y-m');

        return [
            $dashboard->salesperson($w['siti'], $period),
            $dashboard->salesperson($w['rahim'], $period),
            $dashboard->management($w['owner'], $period),
        ];
    });

    expect($siti['revenue'])->toBe('1000.0000')
        ->and($rahim['revenue'])->toBe('500.0000')
        ->and($owner['revenue'])->toBe('1500.0000');
});

it('scopes every numeric figure on the salesperson dashboard', function (): void {
    $w = reportingWorld();

    $figures = inReporting($w['company'], function () use ($w): array {
        app(RollupService::class)->rebuildSales(now());

        return app(DashboardService::class)->salesperson($w['siti'], now()->format('Y-m'));
    });

    expect($figures['orders'])->toBe(1)
        ->and($figures['revenue'])->toBe('1000.0000')
        ->and($figures['margin'])->toBe('400.0000')
        ->and($figures['attainment_percent'])->toBe('100.00');
});

it('gives a scoped user an empty dashboard rather than someone else\'s numbers', function (): void {
    $w = reportingWorld();

    $stranger = inReporting($w['company'], fn () => reportingMember(
        $w['company'],
        'Zul',
        CompanyRole::Salesperson,
        $w['branch'],
        $w['team']
    ));

    $figures = inReporting($w['company'], function () use ($stranger): array {
        app(RollupService::class)->rebuildSales(now());

        return app(DashboardService::class)->salesperson($stranger, now()->format('Y-m'));
    });

    expect($figures['revenue'])->toBe('0.0000')
        ->and($figures['orders'])->toBe(0);
});

it('scopes the management dashboard breakdowns too', function (): void {
    $w = reportingWorld();

    [$ownerTop, $sitiTop] = inReporting($w['company'], function () use ($w): array {
        app(RollupService::class)->rebuildSales(now());
        $dashboard = app(DashboardService::class);
        $period = now()->format('Y-m');

        return [
            $dashboard->management($w['owner'], $period)['top_salespeople'],
            $dashboard->salesperson($w['siti'], $period),
        ];
    });

    expect($ownerTop)->toHaveCount(2)
        ->and($sitiTop['revenue'])->toBe('1000.0000');
});

it('serves every role variant without leaking across scopes', function (): void {
    $w = reportingWorld();

    $variants = inReporting($w['company'], function () use ($w): array {
        app(RollupService::class)->rebuildSales(now());
        $dashboard = app(DashboardService::class);
        $period = now()->format('Y-m');

        return [
            'management' => $dashboard->forRole($w['owner'], 'management', $period)['revenue'],
            'sales' => $dashboard->forRole($w['owner'], 'sales', $period)['revenue'],
            'marketing' => $dashboard->forRole($w['owner'], 'marketing', $period)['revenue'],
            'marketer' => $dashboard->forRole($w['siti'], 'marketer', $period)['revenue'],
            'salesperson' => $dashboard->forRole($w['siti'], 'salesperson', $period)['revenue'],
        ];
    });

    expect($variants['management'])->toBe('1500.0000')
        ->and($variants['sales'])->toBe('1500.0000')
        ->and($variants['marketing'])->toBe('1500.0000')
        ->and($variants['marketer'])->toBe('1000.0000')
        ->and($variants['salesperson'])->toBe('1000.0000');
});

it('never counts another company in a rollup', function (): void {
    $mine = reportingWorld();
    $theirs = reportingWorld();

    $revenue = inReporting($mine['company'], function (): string {
        app(RollupService::class)->rebuildSales(now());

        return (string) SalesRollup::query()->sum('revenue');
    });

    inReporting($theirs['company'], fn () => app(RollupService::class)->rebuildSales(now()));

    $after = inReporting($mine['company'], fn (): string => (string) SalesRollup::query()->sum('revenue'));

    expect($revenue)->toBe('1500.0000')->and($after)->toBe('1500.0000');
});

it('records what each rollup run wrote', function (): void {
    $w = reportingWorld();

    $run = inReporting($w['company'], function () {
        app(RollupService::class)->rebuildSales(now());

        return RollupRun::query()->where('kind', 'sales')->latest('ran_at')->firstOrFail();
    });

    expect($run->rows_written)->toBe(2)->and($run->scope_key)->toBe(now()->toDateString());
});
