<?php

declare(strict_types=1);

use App\Domain\Subscriptions\SubscriptionRefused;
use App\Domain\Subscriptions\SubscriptionService;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

/** @return array<string, mixed> */
function subscriptionFixture(string $interval = 'monthly', string $price = '250'): array
{
    $f = routeFixture();

    $extra = test()->withCompany($f['company'], function () use ($interval, $price): array {
        $product = Product::create(['sku' => 'SUPPORT', 'name' => 'Support retainer', 'type' => 'service', 'is_stock_tracked' => false]);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'SUPPORT-STD',
            'name' => 'Standard',
            'selling_price' => $price,
            'is_default' => true,
        ]);

        $customer = Customer::create(['code' => 'CU-1', 'name' => 'Retainer Client']);

        $plan = SubscriptionPlan::create([
            'product_variant_id' => $variant->getKey(),
            'code' => 'PLAN-'.$interval,
            'name' => ucfirst($interval).' support',
            'interval' => $interval,
            'price' => $price,
        ]);

        return compact('variant', 'customer', 'plan');
    });

    return [...$f, ...$extra];
}

function subs(): SubscriptionService
{
    return app(SubscriptionService::class);
}

it('advances each interval the way a human would read it', function (): void {
    $jan31 = now()->parse('2026-01-31')->toImmutable();

    expect(subs()->advance($jan31, 'monthly')->toDateString())->toBe('2026-02-28', 'no overflow into March')
        ->and(subs()->advance($jan31, 'weekly')->toDateString())->toBe('2026-02-07')
        ->and(subs()->advance($jan31, 'quarterly')->toDateString())->toBe('2026-04-30')
        ->and(subs()->advance($jan31, 'yearly')->toDateString())->toBe('2027-01-31');
});

it('snapshots the price at signup so a plan change does not reprice a subscriber', function (): void {
    $f = subscriptionFixture('monthly', '250');

    $this->withCompany($f['company'], function () use ($f): void {
        $sub = subs()->start($f['customer'], $f['plan'], now()->toImmutable()->startOfDay(), '2', $f['alice']);

        expect((string) $sub->unit_price)->toBe('250.0000')
            ->and($sub->chargeAmount()->toDecimal())->toBe('500.0000');

        $f['plan']->forceFill(['price' => '900'])->save();

        expect((string) $sub->fresh()?->unit_price)->toBe('250.0000', 'the subscriber keeps the price they signed at');

        subs()->billDue(now()->toImmutable());

        $line = OrderItem::query()->firstOrFail();

        expect((string) $line->unit_price)
            ->toBe('250.0000', 'the invoice must charge the agreed price, not the new list price')
            ->and((string) Order::query()->firstOrFail()->total)->toBe('500.0000');
    });
});

it('bills a due subscription once and raises an invoice', function (): void {
    $f = subscriptionFixture('monthly', '250');

    $this->withCompany($f['company'], function () use ($f): void {
        $today = now()->toImmutable()->startOfDay();
        $sub = subs()->start($f['customer'], $f['plan'], $today, '2', $f['alice']);

        $run = subs()->billDue($today);

        expect($run->billed)->toBe(1);

        $order = Order::query()->firstOrFail();
        $invoice = Invoice::query()->firstOrFail();

        expect((string) $order->total)->toBe('500.0000')
            ->and($order->subscription_id)->toBe($sub->getKey())
            ->and((string) $invoice->total)->toBe('500.0000')
            ->and($sub->fresh()?->next_invoice_on?->toDateString())
            ->toBe($today->addMonthNoOverflow()->toDateString());
    });
});

it('cannot bill the same period twice, however many times the run repeats', function (): void {
    $f = subscriptionFixture('monthly', '250');

    $this->withCompany($f['company'], function () use ($f): void {
        $today = now()->toImmutable()->startOfDay();
        subs()->start($f['customer'], $f['plan'], $today, '1', $f['alice']);

        subs()->billDue($today);
        subs()->billDue($today);
        subs()->billDue($today);

        expect(Order::query()->count())->toBe(1, 'a repeated run must never double-bill')
            ->and(Invoice::query()->count())->toBe(1);
    });
});

it('refuses a second charge for a period even if the next date is wound back', function (): void {
    $f = subscriptionFixture('monthly', '250');

    $this->withCompany($f['company'], function () use ($f): void {
        $today = now()->toImmutable()->startOfDay();
        $sub = subs()->start($f['customer'], $f['plan'], $today, '1', $f['alice']);

        subs()->billDue($today);

        expect(Order::query()->count())->toBe(1);

        $sub->fresh()->forceFill(['next_invoice_on' => $today])->save();

        subs()->billDue($today);

        expect(Order::query()->count())
            ->toBe(1, 'the database, not the schedule, is what makes a second charge impossible')
            ->and($sub->fresh()?->next_invoice_on?->toDateString())
            ->toBe($today->addMonthNoOverflow()->toDateString(), 'and it moves the period on rather than sticking');
    });
});

it('bills every period that has fallen due, not just the latest', function (): void {
    $f = subscriptionFixture('monthly', '100');

    $this->withCompany($f['company'], function () use ($f): void {
        $start = now()->toImmutable()->startOfDay()->subMonthsNoOverflow(3);

        subs()->start($f['customer'], $f['plan'], $start, '1', $f['alice']);

        $today = now()->toImmutable()->startOfDay();

        for ($i = 0; $i < 5; $i++) {
            subs()->billDue($today);
        }

        expect(Order::query()->count())->toBe(4, 'three months back plus the current one')
            ->and(Order::query()->sum('total'))->toEqual('400.0000');
    });
});

it('does not bill a paused subscription and resumes without backdating', function (): void {
    $f = subscriptionFixture('monthly', '100');

    $this->withCompany($f['company'], function () use ($f): void {
        $start = now()->toImmutable()->startOfDay()->subMonthsNoOverflow(2);
        $sub = subs()->start($f['customer'], $f['plan'], $start, '1', $f['alice']);

        subs()->pause($sub, $f['owner']);

        $run = subs()->billDue(now()->toImmutable());

        expect(Order::query()->count())->toBe(0, 'a paused subscription bills nothing')
            ->and($run->skipped)->toBe([], 'it must not be selected at all, rather than selected and refused');

        subs()->resume($sub->refresh(), $f['owner']);

        expect($sub->fresh()?->next_invoice_on?->toDateString())
            ->toBe(now()->toImmutable()->startOfDay()->toDateString(), 'resuming must not invoice the months it sat paused');
    });
});

it('stops billing once cancelled', function (): void {
    $f = subscriptionFixture('monthly', '100');

    $this->withCompany($f['company'], function () use ($f): void {
        $today = now()->toImmutable()->startOfDay();
        $sub = subs()->start($f['customer'], $f['plan'], $today, '1', $f['alice']);

        subs()->cancel($sub, 'Client closed the account', $f['owner']);

        subs()->billDue($today->addYear());

        expect(Order::query()->count())->toBe(0)
            ->and($sub->fresh()?->status)->toBe('cancelled');
    });
});

it('refuses a cancellation with no reason', function (): void {
    $f = subscriptionFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $sub = subs()->start($f['customer'], $f['plan'], now()->toImmutable()->startOfDay(), '1', $f['alice']);

        expect(fn () => subs()->cancel($sub, '   ', $f['owner']))
            ->toThrow(SubscriptionRefused::class, 'needs a reason');

        expect($sub->fresh()?->status)->toBe('active');
    });
});

it('refuses starting a subscription on a retired plan', function (): void {
    $f = subscriptionFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $f['plan']->forceFill(['is_active' => false])->save();

        expect(fn () => subs()->start($f['customer'], $f['plan']->refresh(), now()->toImmutable(), '1', $f['alice']))
            ->toThrow(SubscriptionRefused::class, 'no longer offered');

        expect(Subscription::query()->count())->toBe(0);
    });
});

it('ends a subscription rather than billing past its end date', function (): void {
    $f = subscriptionFixture('monthly', '100');

    $this->withCompany($f['company'], function () use ($f): void {
        $today = now()->toImmutable()->startOfDay();
        $sub = subs()->start($f['customer'], $f['plan'], $today, '1', $f['alice']);

        $sub->forceFill(['ends_on' => $today->addDays(10)])->save();

        subs()->billDue($today);
        subs()->billDue($today->addMonthsNoOverflow(2));

        expect(Order::query()->count())->toBe(1, 'only the period inside the term is billed')
            ->and($sub->fresh()?->status)->toBe('ended');
    });
});

it('refuses to charge a period that falls after the term ends', function (): void {
    $f = subscriptionFixture('monthly', '100');

    $this->withCompany($f['company'], function () use ($f): void {
        $today = now()->toImmutable()->startOfDay();
        $sub = subs()->start($f['customer'], $f['plan'], $today, '1', $f['alice']);

        $sub->forceFill([
            'starts_on' => $today->subMonthNoOverflow(),
            'ends_on' => $today->subDay(),
            'next_invoice_on' => $today,
            'status' => 'active',
        ])->save();

        $billed = subs()->billOnce($sub->refresh(), $f['owner']);

        expect($billed)->toBeFalse('the period is past the term, so nothing is charged')
            ->and(Order::query()->count())->toBe(0)
            ->and($sub->fresh()?->status)->toBe('ended');
    });
});

it('bills every company in one scheduled run', function (): void {
    $f = subscriptionFixture('monthly', '100');

    $this->withCompany($f['company'], function () use ($f): void {
        subs()->start($f['customer'], $f['plan'], now()->toImmutable()->startOfDay(), '1', $f['alice']);
    });

    $this->artisan('erp:bill-subscriptions')->assertSuccessful();

    $this->withCompany($f['company'], function (): void {
        expect(Order::query()->count())->toBe(1);
    });
});

it('shows a salesperson only the subscriptions they own', function (): void {
    $f = subscriptionFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Own);

    $this->withCompany($f['company'], function () use ($f): void {
        subs()->start($f['customer'], $f['plan'], now()->toImmutable()->startOfDay(), '1', $f['alice']);

        $other = Customer::create(['code' => 'CU-2', 'name' => 'Somebody else']);
        subs()->start($other, $f['plan'], now()->toImmutable()->startOfDay(), '1', $f['bob']);

        expect(Subscription::query()->visibleTo($f['alice'], 'customers.view')->count())->toBe(1)
            ->and(Subscription::query()->visibleTo($f['owner'], 'customers.view')->count())->toBe(2);
    });
});

it('refuses the subscription screens to a role without customers.view', function (): void {
    $f = subscriptionFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store@acme.test', $f['branch']);

    $this->actingAs($storekeeper)->get('/dashboard')->assertOk();
    $this->actingAs($storekeeper)->get('/subscriptions')->assertForbidden();
});

it('refuses starting a subscription from a role that may only look', function (): void {
    $f = subscriptionFixture();

    grant($f['company'], CompanyRole::Staff, 'customers.view', DataScope::Company);

    $clerk = person($f['company'], CompanyRole::Staff, 'clerk@acme.test', $f['branch']);

    $this->actingAs($clerk)->get('/subscriptions')->assertOk();

    $this->actingAs($clerk)->post('/subscriptions', [
        'customer_id' => $f['customer']->getKey(),
        'subscription_plan_id' => $f['plan']->getKey(),
        'quantity' => '1',
        'starts_on' => now()->toDateString(),
    ])->assertForbidden();

    $this->withCompany($f['company'], function (): void {
        expect(Subscription::query()->count())->toBe(0);
    });
});

it('starts a subscription over HTTP and shows it billing on schedule', function (): void {
    $f = subscriptionFixture('monthly', '250');

    $this->actingAs($f['owner'])->post('/subscriptions', [
        'customer_id' => $f['customer']->getKey(),
        'subscription_plan_id' => $f['plan']->getKey(),
        'quantity' => '2',
        'starts_on' => now()->toDateString(),
    ])->assertRedirect()->assertSessionMissing('error');

    $sub = $this->withCompany($f['company'], fn (): Subscription => Subscription::query()->firstOrFail());

    $this->actingAs($f['owner'])
        ->get("/subscriptions/{$sub->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Subscriptions/Show')
            ->where('subscription.charge', '500.0000')
            ->has('charges', 0));

    $this->artisan('erp:bill-subscriptions')->assertSuccessful();

    $this->actingAs($f['owner'])
        ->get("/subscriptions/{$sub->getKey()}")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('charges', 1));
});

it('refuses a cancellation with no reason at the endpoint', function (): void {
    $f = subscriptionFixture();

    $sub = $this->withCompany(
        $f['company'],
        fn (): Subscription => subs()->start($f['customer'], $f['plan'], now()->toImmutable()->startOfDay(), '1', $f['alice'])
    );

    $this->actingAs($f['owner'])
        ->post("/subscriptions/{$sub->getKey()}/cancel", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->withCompany($f['company'], function () use ($sub): void {
        expect($sub->fresh()?->status)->toBe('active');
    });
});

it('refuses to open a subscription outside the actor data scope', function (): void {
    $f = subscriptionFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Own);

    $bobs = $this->withCompany(
        $f['company'],
        fn (): Subscription => subs()->start($f['customer'], $f['plan'], now()->toImmutable()->startOfDay(), '1', $f['bob'])
    );

    $this->actingAs($f['alice'])->get('/subscriptions')->assertOk();
    $this->actingAs($f['alice'])->get("/subscriptions/{$bobs->getKey()}")->assertForbidden();

    $this->actingAs($f['owner'])->get("/subscriptions/{$bobs->getKey()}")->assertOk();
});
