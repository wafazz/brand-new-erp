<?php

declare(strict_types=1);

use App\Domain\Commission\CommissionConfigurator;
use App\Domain\Commission\CommissionEngine;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

function planWithRule(Company $company, string $strategy = 'percentage_of_value'): array
{
    return test()->withCompany($company, function () use ($strategy): array {
        $plan = CommissionPlan::create([
            'code' => 'PL-'.str()->random(4),
            'name' => 'Standard plan',
            'strategy' => $strategy,
            'recipient_role' => 'salesperson',
        ]);

        $rule = CommissionRule::create([
            'commission_plan_id' => $plan->getKey(),
            'code' => 'RL-'.str()->random(4),
            'name' => 'Base rate',
        ]);

        return [$plan, $rule];
    });
}

it('refuses the plan screen to a role that may only look at commission', function (): void {
    $f = routeFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $accountant): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($accountant->can('commissions.view'))->toBeTrue('an accountant must still see commission')
            ->and($accountant->can('commissions.pay'))->toBeTrue('and still pay it')
            ->and($accountant->can('commissions.configure'))->toBeFalse('but must not rewrite the plans');
    });

    $this->actingAs($accountant)->get('/commissions')->assertOk();
    $this->actingAs($accountant)->get('/commission-plans')->assertForbidden();

    $this->actingAs($f['owner'])
        ->get('/commission-plans')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Finance/Plans/Index'));
});

it('creates a plan and a rule under it', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])->post('/commission-plans', [
        'code' => 'STD',
        'name' => 'Standard sales',
        'strategy' => 'percentage_of_value',
        'recipient_role' => 'salesperson',
        'ad_spend_allocation' => 'pro_rata_by_order_value',
        'is_active' => true,
    ])->assertRedirect();

    $plan = $this->withCompany($f['company'], fn (): CommissionPlan => CommissionPlan::query()->firstOrFail());

    $this->actingAs($f['owner'])
        ->post("/commission-plans/{$plan->getKey()}/rules", ['code' => 'BASE', 'name' => 'Base rate'])
        ->assertRedirect();

    $this->withCompany($f['company'], function () use ($plan): void {
        expect($plan->rules()->count())->toBe(1)
            ->and(CommissionRuleVersion::query()->count())->toBe(0, 'a new rule pays nothing until a rate is published');
    });
});

it('publishes a rate as version one', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company']);

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent',
        'rate_value' => '5',
        'valid_from' => now()->toDateString(),
    ])->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($rule): void {
        $version = CommissionRuleVersion::query()->where('commission_rule_id', $rule->getKey())->firstOrFail();

        expect($version->version)->toBe(1)
            ->and((string) $version->rate_value)->toBe('5.0000')
            ->and($version->valid_to)->toBeNull();
    });
});

it('never touches a published version when a new rate supersedes it', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company']);

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '5', 'valid_from' => now()->subMonth()->toDateString(),
    ])->assertSessionMissing('error');

    $original = $this->withCompany($f['company'], fn (): CommissionRuleVersion => CommissionRuleVersion::query()->firstOrFail());

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '7', 'valid_from' => now()->toDateString(),
    ])->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($rule, $original): void {
        $versions = CommissionRuleVersion::query()
            ->where('commission_rule_id', $rule->getKey())
            ->orderBy('version')
            ->get();

        expect($versions)->toHaveCount(2)
            ->and((string) $versions[0]->rate_value)->toBe('5.0000', 'the original rate must survive untouched')
            ->and($versions[0]->getKey())->toBe($original->getKey())
            ->and($versions[0]->created_at?->equalTo($original->created_at))->toBeTrue('the original row was rewritten')
            ->and($versions[1]->version)->toBe(2)
            ->and((string) $versions[1]->rate_value)->toBe('7.0000');
    });
});

it('applies the newest rate whose start date has passed', function (): void {
    $f = routeFixture();
    [$plan, $rule] = planWithRule($f['company']);

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '5', 'valid_from' => now()->subMonth()->toDateString(),
    ])->assertSessionMissing('error');

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '7', 'valid_from' => now()->addMonth()->toDateString(),
    ])->assertSessionMissing('error');

    $inForce = $this->withCompany(
        $f['company'],
        fn (): ?CommissionRuleVersion => app(CommissionConfigurator::class)->versionInForce($rule)
    );

    expect((string) $inForce?->rate_value)->toBe('5.0000', 'a future-dated rate must not take effect early');

    $this->actingAs($f['owner'])
        ->get("/commission-plans/{$plan->getKey()}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rules.0.versions.0.state', 'scheduled')
            ->where('rules.0.versions.1.state', 'in force'));
});

it('refuses a new rate that starts before the one it replaces', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company']);

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '5', 'valid_from' => now()->toDateString(),
    ])->assertSessionMissing('error');

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '9', 'valid_from' => now()->subMonth()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($rule): void {
        expect(CommissionRuleVersion::query()->where('commission_rule_id', $rule->getKey())->count())->toBe(1);
    });
});

it('refuses a percentage rate above one hundred', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company']);

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '140', 'valid_from' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function (): void {
        expect(CommissionRuleVersion::query()->count())->toBe(0);
    });
});

it('refuses a rate of zero or less', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company']);

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '0', 'valid_from' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function (): void {
        expect(CommissionRuleVersion::query()->count())->toBe(0);
    });
});

it('refuses a fixed rate on a percentage plan', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company'], 'percentage_of_margin');

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'fixed', 'rate_value' => '25', 'valid_from' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function (): void {
        expect(CommissionRuleVersion::query()->count())->toBe(0);
    });
});

it('accepts a fixed rate on a fixed plan', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company'], 'fixed_per_order');

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'fixed', 'rate_value' => '25', 'valid_from' => now()->toDateString(),
    ])->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function (): void {
        expect(CommissionRuleVersion::query()->count())->toBe(1);
    });
});

it('refuses to change the strategy of a plan that has already paid out', function (): void {
    $f = routeFixture();
    [$plan] = planWithRule($f['company']);

    $this->withCompany($f['company'], function () use ($f, $plan): void {
        $order = Order::create(['order_number' => 'SO-1', 'customer_name' => 'Buyer', 'placed_at' => now()]);

        Commission::create([
            'commission_plan_id' => $plan->getKey(),
            'order_id' => $order->getKey(),
            'recipient_user_id' => $f['alice']->getKey(),
            'recipient_role' => 'salesperson',
            'status' => 'pending',
            'period' => now()->format('Y-m'),
            'basis_amount' => '100',
            'rate_type' => 'percent',
            'rate_applied' => '5',
            'amount' => '5',
            'calc_inputs' => ['note' => 'fixture'],
        ]);
    });

    $this->actingAs($f['owner'])->put("/commission-plans/{$plan->getKey()}", [
        'code' => $plan->code,
        'name' => $plan->name,
        'strategy' => 'fixed_per_order',
        'recipient_role' => 'salesperson',
        'ad_spend_allocation' => 'pro_rata_by_order_value',
        'is_active' => true,
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($plan): void {
        expect($plan->fresh()?->strategy)->toBe('percentage_of_value');
    });
});

it('still allows renaming and stopping a plan that has paid out', function (): void {
    $f = routeFixture();
    [$plan] = planWithRule($f['company']);

    $this->withCompany($f['company'], function () use ($f, $plan): void {
        $order = Order::create(['order_number' => 'SO-2', 'customer_name' => 'Buyer', 'placed_at' => now()]);

        Commission::create([
            'commission_plan_id' => $plan->getKey(),
            'order_id' => $order->getKey(),
            'recipient_user_id' => $f['alice']->getKey(),
            'recipient_role' => 'salesperson',
            'status' => 'pending',
            'period' => now()->format('Y-m'),
            'basis_amount' => '100',
            'rate_type' => 'percent',
            'rate_applied' => '5',
            'amount' => '5',
            'calc_inputs' => ['note' => 'fixture'],
        ]);
    });

    $this->actingAs($f['owner'])->put("/commission-plans/{$plan->getKey()}", [
        'code' => $plan->code,
        'name' => 'Renamed plan',
        'strategy' => 'percentage_of_value',
        'recipient_role' => 'salesperson',
        'ad_spend_allocation' => 'pro_rata_by_order_value',
        'is_active' => false,
    ])->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($plan): void {
        expect($plan->fresh()?->name)->toBe('Renamed plan')
            ->and($plan->fresh()?->is_active)->toBeFalse();
    });
});

it('refuses a salesperson publishing a rate on their own commission', function (): void {
    $f = routeFixture();
    [, $rule] = planWithRule($f['company']);

    grant($f['company'], CompanyRole::Salesperson, 'commissions.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->post("/commission-rules/{$rule->getKey()}/versions", [
            'rate_type' => 'percent', 'rate_value' => '50', 'valid_from' => now()->toDateString(),
        ])
        ->assertForbidden();

    $this->withCompany($f['company'], function (): void {
        expect(CommissionRuleVersion::query()->count())->toBe(0);
    });
});

it('shows the full version history on the plan screen', function (): void {
    $f = routeFixture();
    [$plan, $rule] = planWithRule($f['company']);

    foreach ([['5', 2], ['7', 1]] as [$rate, $monthsAgo]) {
        $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
            'rate_type' => 'percent', 'rate_value' => $rate, 'valid_from' => now()->subMonths($monthsAgo)->toDateString(),
        ])->assertSessionMissing('error');
    }

    $this->actingAs($f['owner'])
        ->get("/commission-plans/{$plan->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Finance/Plans/Show')
            ->has('rules.0.versions', 2)
            ->where('rules.0.versions.0.version', 2)
            ->where('rules.0.versions.0.state', 'in force')
            ->where('rules.0.versions.1.state', 'superseded'));
});

it('pays commission on a real order once a plan is configured through these screens', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'commissions.view', DataScope::Company);

    $this->actingAs($f['owner'])->post('/commission-plans', [
        'code' => 'STD',
        'name' => 'Standard sales',
        'strategy' => 'percentage_of_value',
        'recipient_role' => 'salesperson',
        'ad_spend_allocation' => 'pro_rata_by_order_value',
        'is_active' => true,
    ])->assertSessionMissing('error');

    $plan = $this->withCompany($f['company'], fn (): CommissionPlan => CommissionPlan::query()->firstOrFail());

    $this->actingAs($f['owner'])
        ->post("/commission-plans/{$plan->getKey()}/rules", ['code' => 'BASE', 'name' => 'Base rate'])
        ->assertSessionMissing('error');

    $rule = $this->withCompany($f['company'], fn (): CommissionRule => CommissionRule::query()->firstOrFail());

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '5', 'valid_from' => now()->subDay()->toDateString(),
    ])->assertSessionMissing('error');

    $variant = $this->withCompany($f['company'], function (): ProductVariant {
        $product = Product::create(['sku' => 'SELL', 'name' => 'Sellable']);

        return ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'SELL-A',
            'name' => 'Default',
            'selling_price' => '200',
            'is_default' => true,
        ]);
    });

    $this->actingAs($f['alice'])->post('/orders', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
    ])->assertRedirect();

    $order = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    expect((string) $order->total)->toBe('400.0000');

    $accrued = $this->withCompany(
        $f['company'],
        fn () => app(CommissionEngine::class)->accrueForOrder($order->refresh(), $f['owner'])
    );

    expect($accrued)->toHaveCount(1, 'the configured plan must accrue exactly once');

    $this->withCompany($f['company'], function () use ($f, $rule): void {
        $commission = Commission::query()->firstOrFail();

        expect((string) $commission->amount)->toBe('20.0000', '5% of a 400 order')
            ->and($commission->recipient_user_id)->toBe($f['alice']->getKey())
            ->and($commission->commission_rule_id)->toBe($rule->getKey());
    });

    $this->actingAs($f['owner'])
        ->get('/commissions?period='.now()->format('Y-m'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('commissions.data', 1)
            ->where('commissions.data.0.amount', '20.0000'));
});

it('accrues nothing while no plan is configured', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);

    $variant = $this->withCompany($f['company'], function (): ProductVariant {
        $product = Product::create(['sku' => 'SELL2', 'name' => 'Sellable']);

        return ProductVariant::create([
            'product_id' => $product->getKey(), 'sku' => 'SELL2-A', 'name' => 'Default',
            'selling_price' => '200', 'is_default' => true,
        ]);
    });

    $this->actingAs($f['alice'])->post('/orders', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
    ])->assertRedirect();

    $order = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    $accrued = $this->withCompany(
        $f['company'],
        fn () => app(CommissionEngine::class)->accrueForOrder($order->refresh())
    );

    expect($accrued)->toHaveCount(0)
        ->and($this->withCompany($f['company'], fn (): int => Commission::query()->count()))->toBe(0);
});

it('pays the upline manager when the plan names that recipient', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);

    $this->withCompany($f['company'], function () use ($f): void {
        CompanyUser::query()
            ->where('user_id', $f['alice']->getKey())
            ->update(['manager_id' => $f['owner']->getKey()]);
    });

    $this->actingAs($f['owner'])->post('/commission-plans', [
        'code' => 'UP', 'name' => 'Override', 'strategy' => 'percentage_of_value',
        'recipient_role' => 'upline', 'ad_spend_allocation' => 'excluded', 'is_active' => true,
    ])->assertSessionMissing('error');

    $plan = $this->withCompany($f['company'], fn (): CommissionPlan => CommissionPlan::query()->firstOrFail());

    $this->actingAs($f['owner'])->post("/commission-plans/{$plan->getKey()}/rules", ['code' => 'OVR', 'name' => 'Override rate']);

    $rule = $this->withCompany($f['company'], fn (): CommissionRule => CommissionRule::query()->firstOrFail());

    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '2', 'valid_from' => now()->subDay()->toDateString(),
    ])->assertSessionMissing('error');

    $variant = $this->withCompany($f['company'], function (): ProductVariant {
        $product = Product::create(['sku' => 'UP1', 'name' => 'Product']);

        return ProductVariant::create([
            'product_id' => $product->getKey(), 'sku' => 'UP1-A', 'name' => 'Default',
            'selling_price' => '500', 'is_default' => true,
        ]);
    });

    $this->actingAs($f['alice'])->post('/orders', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '1']],
    ])->assertRedirect();

    $order = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    $this->withCompany(
        $f['company'],
        fn () => app(CommissionEngine::class)->accrueForOrder($order->refresh())
    );

    $this->withCompany($f['company'], function () use ($f): void {
        $commission = Commission::query()->firstOrFail();

        expect($commission->recipient_user_id)->toBe($f['owner']->getKey(), 'the manager, not the seller')
            ->and((string) $commission->amount)->toBe('10.0000', '2% of 500');
    });
});

it('accrues nothing on an upline plan when the seller has no manager', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);

    $this->actingAs($f['owner'])->post('/commission-plans', [
        'code' => 'UP2', 'name' => 'Override', 'strategy' => 'percentage_of_value',
        'recipient_role' => 'upline', 'ad_spend_allocation' => 'excluded', 'is_active' => true,
    ])->assertSessionMissing('error');

    $plan = $this->withCompany($f['company'], fn (): CommissionPlan => CommissionPlan::query()->firstOrFail());
    $this->actingAs($f['owner'])->post("/commission-plans/{$plan->getKey()}/rules", ['code' => 'OVR2', 'name' => 'Override rate']);
    $rule = $this->withCompany($f['company'], fn (): CommissionRule => CommissionRule::query()->firstOrFail());
    $this->actingAs($f['owner'])->post("/commission-rules/{$rule->getKey()}/versions", [
        'rate_type' => 'percent', 'rate_value' => '2', 'valid_from' => now()->subDay()->toDateString(),
    ]);

    $variant = $this->withCompany($f['company'], function (): ProductVariant {
        $product = Product::create(['sku' => 'UP2', 'name' => 'Product']);

        return ProductVariant::create([
            'product_id' => $product->getKey(), 'sku' => 'UP2-A', 'name' => 'Default',
            'selling_price' => '500', 'is_default' => true,
        ]);
    });

    $this->actingAs($f['alice'])->post('/orders', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '1']],
    ])->assertRedirect();

    $order = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    $accrued = $this->withCompany(
        $f['company'],
        fn () => app(CommissionEngine::class)->accrueForOrder($order->refresh())
    );

    expect($accrued)->toHaveCount(0, 'no manager means nobody to pay, and the engine says so by paying nobody');
});
