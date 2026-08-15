<?php

declare(strict_types=1);

use App\Domain\Attribution\AttributionService;
use App\Domain\Commission\CommissionEngine;
use App\Domain\Commission\CommissionNotPermitted;
use App\Domain\Commission\CommissionStateMachine;
use App\Domain\Orders\OrderService;
use App\Models\Campaign;
use App\Models\CampaignCost;
use App\Models\Channel;
use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Models\CommissionSource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Marketer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function commissionWorld(string $adSpend = '80', string $rate = '12'): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $adSpend, $rate): array {
        $channel = Channel::create(['code' => 'FB', 'name' => 'Facebook']);
        $aliUser = User::create(['name' => 'Ali', 'email' => 'ali'.str()->random(4).'@a.test', 'password' => 'secret-password']);
        $ali = Marketer::create(['user_id' => $aliUser->getKey(), 'code' => 'MK-ALI']);

        $campaign = Campaign::create([
            'channel_id' => $channel->getKey(),
            'marketer_id' => $ali->getKey(),
            'code' => 'RAYA',
            'name' => 'Raya',
        ]);

        CampaignCost::create([
            'campaign_id' => $campaign->getKey(),
            'period' => now()->format('Y-m'),
            'amount' => $adSpend,
            'spent_on' => now()->toDateString(),
        ]);

        $plan = CommissionPlan::create([
            'code' => 'MARGIN12',
            'name' => 'Marketer margin plan',
            'strategy' => 'percentage_of_margin',
            'recipient_role' => 'marketer',
            'ad_spend_allocation' => 'pro_rata_by_order_value',
        ]);

        $rule = CommissionRule::create([
            'commission_plan_id' => $plan->getKey(),
            'code' => 'FB-MARGIN',
            'name' => 'Facebook Campaign Margin',
        ]);

        $version = CommissionRuleVersion::create([
            'commission_rule_id' => $rule->getKey(),
            'version' => 1,
            'rate_type' => 'percent',
            'rate_value' => $rate,
            'valid_from' => now()->subYear(),
        ]);

        $product = Product::create(['sku' => 'WIDGET', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '500.0000',
            'cost_price' => '260.0000',
        ]);

        $customer = Customer::create(['code' => 'C1', 'name' => 'Aminah']);
        $lead = Lead::create(['reference' => 'LD-1', 'name' => 'Aminah', 'converted_customer_id' => $customer->getKey(), 'captured_at' => now()]);

        $touchService = app(AttributionService::class);

        $touchService->recordTouch($lead, [
            'channel_id' => $channel->getKey(),
            'campaign_id' => $campaign->getKey(),
            'marketer_id' => $ali->getKey(),
            'source' => 'facebook',
        ]);

        $touchService->resolveFor($customer);

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->getKey(),
            'customer_name' => 'Aminah',
            'lead_id' => $lead->getKey(),
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
        ]);

        $order->forceFill(['shipping_cost' => '49.2000', 'payment_fee' => '30.0000'])->save();

        return compact('company', 'campaign', 'ali', 'aliUser', 'plan', 'rule', 'version', 'variant', 'customer', 'order');
    });
}

function inCommission(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

it('accrues a provisional commission on the full gross margin', function (): void {
    $w = commissionWorld();

    $commission = inCommission($w['company'], fn () => app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first());

    expect((string) $commission->basis_amount)->toBe('320.8000')
        ->and((string) $commission->amount)->toBe('38.4960')
        ->and($commission->is_provisional)->toBeTrue()
        ->and($commission->status)->toBe('pending')
        ->and($commission->recipient_user_id)->toBe($w['aliUser']->getKey());
});

it('persists the whole deduction breakdown rather than a prose note', function (): void {
    $w = commissionWorld();

    $commission = inCommission($w['company'], fn () => app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first());
    $margin = $commission->calc_inputs['margin'];

    expect($margin['sales'])->toBe('1000.0000')
        ->and($margin['cost'])->toBe('520.0000')
        ->and($margin['shipping'])->toBe('49.2000')
        ->and($margin['fees'])->toBe('30.0000')
        ->and($margin['ad_spend'])->toBe('80.0000')
        ->and($margin['margin'])->toBe('320.8000')
        ->and($margin['components'])->toHaveCount(5)
        ->and($commission->calc_inputs['rule_version'])->toBe(1);
});

it('explains itself in one readable line', function (): void {
    $w = commissionWorld();

    $line = inCommission($w['company'], function () use ($w): string {
        $commission = app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first();

        return app(CommissionEngine::class)->explain($commission);
    });

    expect($line)->toContain('Commission MYR 38.50')
        ->and($line)->toContain('Recipient: Ali (marketer)')
        ->and($line)->toContain('Rule: "Facebook Campaign Margin" v1')
        ->and($line)->toContain('12% of MYR 320.80')
        ->and($line)->toContain('Sales MYR 1,000.00 − Cost MYR 520.00 − Shipping MYR 49.20 − Fees MYR 30.00 − Ads MYR 80.00 = MYR 320.80')
        ->and($line)->toContain('Provisional — final at period close');
});

it('is idempotent when the accrual is re-run', function (): void {
    $w = commissionWorld();

    $count = inCommission($w['company'], function () use ($w): int {
        $engine = app(CommissionEngine::class);
        $engine->accrueForOrder($w['order']->refresh());
        $engine->accrueForOrder($w['order']->refresh());
        $engine->accrueForOrder($w['order']->refresh());

        return Commission::query()->count();
    });

    expect($count)->toBe(1);
});

it('refuses a duplicate accrual at the database level', function (): void {
    $w = commissionWorld();

    inCommission($w['company'], function () use ($w): void {
        $first = app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first();

        $duplicate = $first->replicate(['id']);

        expect(fn () => $duplicate->save())->toThrow(QueryException::class);
    });
});

it('never rewrites an accrual that has been approved', function (): void {
    $w = commissionWorld();

    $after = inCommission($w['company'], function () use ($w): Commission {
        $engine = app(CommissionEngine::class);
        $commission = $engine->accrueForOrder($w['order']->refresh())->first();

        $commission->forceFill(['is_provisional' => false])->save();
        app(CommissionStateMachine::class)->transition($commission->refresh(), 'approved');

        $w['order']->forceFill(['payment_fee' => '999.0000'])->save();
        $engine->accrueForOrder($w['order']->refresh());

        return Commission::query()->firstOrFail();
    });

    expect((string) $after->amount)->toBe('38.4960')->and($after->status)->toBe('approved');
});

it('recalculates a pending accrual when the inputs change', function (): void {
    $w = commissionWorld();

    $after = inCommission($w['company'], function () use ($w): Commission {
        $engine = app(CommissionEngine::class);
        $engine->accrueForOrder($w['order']->refresh());

        $w['order']->forceFill(['payment_fee' => '0.0000'])->save();
        $engine->accrueForOrder($w['order']->refresh());

        return Commission::query()->firstOrFail();
    });

    expect((string) $after->basis_amount)->toBe('350.8000')
        ->and((string) $after->amount)->toBe('42.0960');
});

it('refuses to make a provisional commission payable', function (): void {
    $w = commissionWorld();

    inCommission($w['company'], function () use ($w): void {
        $commission = app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first();
        $machine = app(CommissionStateMachine::class);

        $commission = $machine->transition($commission, 'approved');

        expect($machine->reasonAgainst($commission, 'payable'))
            ->toBe('This commission is still provisional. Close the period against reconciled costs before making it payable.')
            ->and(fn () => $machine->transition($commission, 'payable'))->toThrow(CommissionNotPermitted::class);
    });
});

it('refuses a provisional payable row at the database level too', function (): void {
    $w = commissionWorld();

    inCommission($w['company'], function () use ($w): void {
        $commission = app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first();

        expect(fn () => $commission->forceFill(['status' => 'payable'])->save())
            ->toThrow(QueryException::class);
    });
});

it('restates the commission when the period closes against reconciled costs', function (): void {
    $w = commissionWorld();

    $final = inCommission($w['company'], function () use ($w): Commission {
        $engine = app(CommissionEngine::class);
        $commission = $engine->accrueForOrder($w['order']->refresh())->first();

        $w['order']->forceFill(['payment_fee' => '25.0000', 'costs_reconciled' => true])->save();

        return $engine->finalise($commission->refresh());
    });

    expect($final->is_provisional)->toBeFalse()
        ->and($final->finalised_at)->not->toBeNull()
        ->and((string) $final->basis_amount)->toBe('325.8000')
        ->and((string) $final->amount)->toBe('39.0960');
});

it('refuses to finalise while costs are unreconciled', function (): void {
    $w = commissionWorld();

    inCommission($w['company'], function () use ($w): void {
        $commission = app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first();

        expect(fn () => app(CommissionEngine::class)->finalise($commission))
            ->toThrow(CommissionNotPermitted::class, 'costs are not reconciled yet');
    });
});

it('lets a finalised commission become payable', function (): void {
    $w = commissionWorld();

    $status = inCommission($w['company'], function () use ($w): string {
        $engine = app(CommissionEngine::class);
        $commission = $engine->accrueForOrder($w['order']->refresh())->first();

        $w['order']->forceFill(['costs_reconciled' => true])->save();
        $commission = $engine->finalise($commission->refresh());

        $machine = app(CommissionStateMachine::class);
        $commission = $machine->transition($commission, 'approved');

        return $machine->transition($commission, 'payable')->status;
    });

    expect($status)->toBe('payable');
});

it('reverses with a contra entry rather than deleting', function (): void {
    $w = commissionWorld();

    [$original, $contra] = inCommission($w['company'], function () use ($w): array {
        $engine = app(CommissionEngine::class);
        $original = $engine->accrueForOrder($w['order']->refresh())->first();
        $contra = $engine->reverse($original, 'Order refunded.');

        return [$original->refresh(), $contra];
    });

    expect($original->status)->toBe('reversed')
        ->and($contra->type)->toBe('reversal')
        ->and((string) $contra->amount)->toBe('-38.4960')
        ->and($contra->reverses_commission_id)->toBe($original->getKey())
        ->and($contra->calc_inputs['reason'])->toBe('Order refunded.');
});

it('negates the sources on a contra entry so the trail nets to zero', function (): void {
    $w = commissionWorld();

    $net = inCommission($w['company'], function () use ($w): string {
        $engine = app(CommissionEngine::class);
        $original = $engine->accrueForOrder($w['order']->refresh())->first();
        $engine->reverse($original, 'Order refunded.');

        return (string) CommissionSource::query()->sum('contribution');
    });

    expect(bccomp($net, '0', 4))->toBe(0);
});

it('refuses to reverse a reversal or reverse twice', function (): void {
    $w = commissionWorld();

    inCommission($w['company'], function () use ($w): void {
        $engine = app(CommissionEngine::class);
        $original = $engine->accrueForOrder($w['order']->refresh())->first();
        $contra = $engine->reverse($original, 'Order refunded.');

        expect(fn () => $engine->reverse($contra, 'again'))->toThrow(CommissionNotPermitted::class, 'A reversal cannot itself be reversed.')
            ->and(fn () => $engine->reverse($original->refresh(), 'again'))->toThrow(CommissionNotPermitted::class, 'already been reversed');
    });
});

it('never deletes a commission row', function (): void {
    $w = commissionWorld();

    inCommission($w['company'], function () use ($w): void {
        $commission = app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first();

        expect(fn () => $commission->delete())->toThrow(QueryException::class);
    });
});

it('keeps a rule version immutable so a closed period cannot be rewritten', function (): void {
    $w = commissionWorld();

    inCommission($w['company'], function () use ($w): void {
        expect(fn () => $w['version']->forceFill(['rate_value' => '99'])->save())
            ->toThrow(RuntimeException::class, 'immutable');
    });
});

it('enforces rule version immutability in the database, not just the model', function (): void {
    $w = commissionWorld();

    expect(fn () => DB::statement(
        'update commission_rule_versions set rate_value = 99 where id = ?',
        [$w['version']->getKey()]
    ))->toThrow(QueryException::class, 'immutable');
});

it('enforces the no-delete rule in the database, not just the model', function (): void {
    $w = commissionWorld();

    $commission = inCommission($w['company'], fn () => app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first());

    expect(fn () => DB::statement('delete from commissions where id = ?', [$commission->getKey()]))
        ->toThrow(QueryException::class, 'contra entry');
});

it('leaves an existing commission on its own rule version when the rate changes', function (): void {
    $w = commissionWorld();

    [$first, $second] = inCommission($w['company'], function () use ($w): array {
        $engine = app(CommissionEngine::class);
        $first = $engine->accrueForOrder($w['order']->refresh())->first();

        CommissionRuleVersion::create([
            'commission_rule_id' => $w['rule']->getKey(),
            'version' => 2,
            'rate_type' => 'percent',
            'rate_value' => '20',
            'valid_from' => now()->addYear(),
        ]);

        $engine->accrueForOrder($w['order']->refresh());

        return [$first, Commission::query()->firstOrFail()];
    });

    expect($second->commission_rule_version_id)->toBe($first->commission_rule_version_id)
        ->and((string) $second->rate_applied)->toBe('12.0000');
});

it('links the commission back to the orders that produced it', function (): void {
    $w = commissionWorld();

    $sources = inCommission($w['company'], function () use ($w) {
        $commission = app(CommissionEngine::class)->accrueForOrder($w['order']->refresh())->first();

        return $commission->sources()->get();
    });

    expect($sources)->toHaveCount(1)
        ->and($sources[0]->order_id)->toBe($w['order']->getKey())
        ->and((string) $sources[0]->contribution)->toBe('320.8000');
});

it('apportions campaign ad spend across the orders that share it', function (): void {
    $w = commissionWorld(adSpend: '100');

    $shares = inCommission($w['company'], function () use ($w): array {
        $second = app(OrderService::class)->create([
            'customer_id' => $w['customer']->getKey(),
            'customer_name' => 'Aminah',
            'lines' => [['variant_id' => $w['variant']->getKey(), 'quantity' => '2']],
        ]);

        $engine = app(CommissionEngine::class);
        $engine->accrueForOrder($w['order']->refresh());
        $engine->accrueForOrder($second->refresh());

        return Commission::query()->orderBy('created_at')->get()
            ->map(fn (Commission $c): string => $c->calc_inputs['margin']['ad_spend'])
            ->all();
    });

    expect($shares)->toHaveCount(2)
        ->and($shares[0])->toBe('50.0000')
        ->and($shares[1])->toBe('50.0000');
});

it('accrues nothing for an order with no attributed recipient', function (): void {
    $w = commissionWorld();

    $count = inCommission($w['company'], function () use ($w): int {
        $walkIn = app(OrderService::class)->create([
            'customer_name' => 'Walk-in',
            'lines' => [['variant_id' => $w['variant']->getKey(), 'quantity' => '1']],
        ]);

        app(CommissionEngine::class)->accrueForOrder($walkIn->refresh());

        return Commission::query()->where('order_id', $walkIn->getKey())->count();
    });

    expect($count)->toBe(0);
});
