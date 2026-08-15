<?php

declare(strict_types=1);

use App\Domain\Payments\PaymentLinkSweeper;
use App\Domain\Subscriptions\SubscriptionService;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Access\RoleProvisioner;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);

    config()->set('billplz', [
        'enabled' => true,
        'sandbox' => true,
        'api_key' => 'test-api-key',
        'x_signature_key' => 'test-signature-key',
        'collection_id' => 'test-collection',
    ]);

    $this->bills = 0;
    $this->billplzStatus = 200;

    Http::fake(function () {
        if ($this->billplzStatus !== 200) {
            return Http::response(['error' => 'refused'], $this->billplzStatus);
        }

        $this->bills++;

        return Http::response(['id' => 'bill-'.$this->bills, 'url' => 'https://www.billplz-sandbox.com/bills/'.$this->bills], 200);
    });
});

function linkFixture(bool $collectOnline, string $price = '250'): array
{
    $f = routeFixture();

    $extra = test()->withCompany($f['company'], function () use ($price, $collectOnline, $f): array {
        $product = Product::create(['sku' => 'SUPPORT', 'name' => 'Support retainer', 'type' => 'service', 'is_stock_tracked' => false]);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'SUPPORT-STD',
            'name' => 'Standard',
            'selling_price' => $price,
            'is_default' => true,
        ]);

        $customer = Customer::create(['code' => 'CU-1', 'name' => 'Retainer Client', 'email' => 'client@example.test']);

        $plan = SubscriptionPlan::create([
            'product_variant_id' => $variant->getKey(),
            'code' => 'PLAN-monthly',
            'name' => 'Monthly support',
            'interval' => 'monthly',
            'price' => $price,
        ]);

        $sub = app(SubscriptionService::class)->start($customer, $plan, now()->toImmutable(), '1', $f['owner']);
        $sub->forceFill(['collect_online' => $collectOnline])->save();

        return ['plan' => $plan, 'customer' => $customer, 'subscription' => $sub->refresh()];
    });

    return [...$f, ...$extra];
}

function sweeper(): PaymentLinkSweeper
{
    return app(PaymentLinkSweeper::class);
}

it('raises a payment link for a subscription invoice set to collect online', function (): void {
    $f = linkFixture(true);

    $result = $this->withCompany($f['company'], function () use ($f) {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        return sweeper()->sweep();
    });

    expect($result->raised)->toBe(1)
        ->and($result->failed)->toBeEmpty();

    $intent = PaymentIntent::acrossCompanies()->firstOrFail();

    expect($intent->pay_url)->toContain('billplz-sandbox.com')
        ->and((string) $intent->amount)->toBe('250.0000');
});

it('leaves a subscription alone when it is not set to collect online', function (): void {
    $f = linkFixture(false);

    $result = $this->withCompany($f['company'], function () use ($f) {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        return sweeper()->sweep();
    });

    expect($result->raised)->toBe(0)
        ->and(PaymentIntent::acrossCompanies()->count())->toBe(0);
});

it('does not raise a second link for an invoice that already has one', function (): void {
    $f = linkFixture(true);

    [$first, $second, $third] = $this->withCompany($f['company'], function () use ($f): array {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        return [sweeper()->sweep(), sweeper()->sweep(), sweeper()->sweep()];
    });

    expect(PaymentIntent::acrossCompanies()->count())->toBe(1)
        ->and($this->bills)->toBe(1)
        ->and($first->raised)->toBe(1);

    // The service hands back the same intent anyway, so the count is the only place the
    // mistake shows: a nightly log claiming work it never did.
    expect($second->raised)->toBe(0, 'the second sweep reported raising a link it did not raise')
        ->and($third->raised)->toBe(0)
        ->and($second->failed)->toBeEmpty();
});

it('raises one link per billing period, not one per invoice ever', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);
        sweeper()->sweep();

        // Next month comes round; a second invoice is raised and needs its own link.
        $sub = Subscription::query()->findOrFail($f['subscription']->getKey());
        $sub->forceFill(['next_invoice_on' => now()->subDay()->toDateString()])->save();

        app(SubscriptionService::class)->billOnce($sub->refresh(), $f['owner']);
        sweeper()->sweep();
    });

    expect(PaymentIntent::acrossCompanies()->count())->toBe(2)
        ->and(Invoice::acrossCompanies()->count())->toBe(2);
});

it('stops asking once the invoice is paid', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        $invoice = Invoice::query()->firstOrFail();
        $invoice->forceFill(['paid_amount' => '250', 'status' => 'paid'])->save();

        $result = sweeper()->sweep();

        // Not merely "raised nothing" — a settled invoice must never reach the service,
        // or every night reports a failure for every invoice ever paid.
        expect($result->raised)->toBe(0)
            ->and($result->failed)->toBeEmpty('a paid invoice was still offered to Billplz');
    });

    expect(PaymentIntent::acrossCompanies()->count())->toBe(0);
});

it('stops asking once the invoice is void', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        Invoice::query()->firstOrFail()->forceFill(['status' => 'void', 'voided_at' => now()])->save();

        $result = sweeper()->sweep();

        expect($result->raised)->toBe(0)
            ->and($result->failed)->toBeEmpty('a void invoice was still offered to Billplz');
    });

    expect(PaymentIntent::acrossCompanies()->count())->toBe(0);
});

it('still collects the remainder of a part-paid subscription invoice', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        Invoice::query()->firstOrFail()->forceFill(['paid_amount' => '100', 'status' => 'partially_paid'])->save();

        expect(sweeper()->sweep()->raised)->toBe(1);
    });

    expect((string) PaymentIntent::acrossCompanies()->firstOrFail()->amount)->toBe('150.0000');
});

it('never touches an invoice that did not come from a subscription', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        // A hand-raised invoice with no subscription behind it.
        $order = Order::create([
            'order_number' => 'SO-MANUAL',
            'customer_name' => 'Walk-in',
            'placed_at' => now(),
        ]);
        $order->forceFill(['subtotal' => '999', 'total' => '999'])->save();

        $manual = Invoice::create([
            'order_id' => $order->getKey(),
            'invoice_number' => 'INV-MANUAL',
            'status' => 'issued',
            'customer_name' => 'Walk-in',
            'issued_at' => now(),
        ]);
        $manual->forceFill(['subtotal' => '999', 'total' => '999'])->save();

        expect(sweeper()->sweep()->raised)->toBe(1);
    });

    $intent = PaymentIntent::acrossCompanies()->firstOrFail();

    expect((string) $intent->amount)->toBe('250.0000');
});

it('does nothing at all when Billplz is not configured', function (): void {
    $f = linkFixture(true);

    config()->set('billplz.api_key', null);

    $result = $this->withCompany($f['company'], function () use ($f) {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);

        return sweeper()->sweep();
    });

    // The nightly run must not fail because an optional integration is switched off.
    expect($result->skippedUnconfigured)->toBeTrue()
        ->and($result->raised)->toBe(0)
        ->and($result->summary())->toContain('not configured')
        ->and($this->bills)->toBe(0);
});

it('carries on when Billplz refuses one bill', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);
    });

    $this->billplzStatus = 422;

    $result = $this->withCompany($f['company'], fn () => sweeper()->sweep());

    expect($result->raised)->toBe(0)
        ->and($result->failed)->toHaveCount(1)
        ->and($result->failed[0])->toContain('refused to create the bill');
});

it('sweeps one company without reaching into another', function (): void {
    $first = linkFixture(true);

    $second = Company::create(['name' => 'Beta Sdn Bhd', 'slug' => 'beta-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($second);

    $other = $this->withCompany($second, function (): Subscription {
        $product = Product::create(['sku' => 'S2', 'name' => 'Other retainer', 'type' => 'service', 'is_stock_tracked' => false]);
        $variant = ProductVariant::create(['product_id' => $product->getKey(), 'sku' => 'S2-STD', 'name' => 'Std', 'selling_price' => '900', 'is_default' => true]);
        $customer = Customer::create(['code' => 'CU-B', 'name' => 'Beta Client']);
        $plan = SubscriptionPlan::create(['product_variant_id' => $variant->getKey(), 'code' => 'P2', 'name' => 'P2', 'interval' => 'monthly', 'price' => '900']);

        $sub = app(SubscriptionService::class)->start($customer, $plan, now()->toImmutable());
        $sub->forceFill(['collect_online' => true])->save();

        app(SubscriptionService::class)->billOnce($sub->refresh());

        return $sub;
    });

    $this->withCompany($first['company'], function () use ($first): void {
        app(SubscriptionService::class)->billOnce($first['subscription'], $first['owner']);

        expect(sweeper()->sweep()->raised)->toBe(1);
    });

    expect(PaymentIntent::acrossCompanies()->count())->toBe(1)
        ->and((string) PaymentIntent::acrossCompanies()->firstOrFail()->amount)->toBe('250.0000');

    $this->withCompany($second, function (): void {
        expect(sweeper()->sweep()->raised)->toBe(1);
    });

    expect(PaymentIntent::acrossCompanies()->count())->toBe(2);
});

it('raises links for every company when the command runs unattended', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);
    });

    $this->artisan('erp:raise-payment-links')
        ->expectsOutputToContain('1 payment link raised')
        ->assertSuccessful();

    expect(PaymentIntent::acrossCompanies()->count())->toBe(1);
});

it('reports failure from the command when a bill cannot be raised', function (): void {
    $f = linkFixture(true);

    $this->withCompany($f['company'], function () use ($f): void {
        app(SubscriptionService::class)->billOnce($f['subscription'], $f['owner']);
    });

    $this->billplzStatus = 500;

    $this->artisan('erp:raise-payment-links')->assertFailed();
});

it('refuses the collect-online switch to a role without payments.create', function (): void {
    $f = linkFixture(false);

    grant($f['company'], CompanyRole::Salesperson, 'customers.update', DataScope::Company);

    // Posting straight to the endpoint with the button hidden.
    $this->actingAs($f['alice'])
        ->post("/subscriptions/{$f['subscription']->getKey()}/collect-online", ['collect_online' => true])
        ->assertForbidden();

    expect(Subscription::acrossCompanies()->findOrFail($f['subscription']->getKey())->collect_online)->toBeFalse();
});

it('lets an accountant switch collection on and off', function (): void {
    $f = linkFixture(false);

    $accountant = person($f['company'], CompanyRole::Accountant, 'ar@acme.test', $f['branch']);
    grant($f['company'], CompanyRole::Accountant, 'payments.create', DataScope::Company);
    grant($f['company'], CompanyRole::Accountant, 'customers.view', DataScope::Company);

    $id = $f['subscription']->getKey();

    $this->actingAs($accountant)->post("/subscriptions/{$id}/collect-online", ['collect_online' => true])->assertRedirect();
    expect(Subscription::acrossCompanies()->findOrFail($id)->collect_online)->toBeTrue();

    $this->actingAs($accountant)->post("/subscriptions/{$id}/collect-online", ['collect_online' => false])->assertRedirect();
    expect(Subscription::acrossCompanies()->findOrFail($id)->collect_online)->toBeFalse();
});

it('does not let a salesperson switch collection on while creating a subscription', function (): void {
    $f = linkFixture(false);

    grant($f['company'], CompanyRole::Salesperson, 'customers.update', DataScope::Company);

    $this->actingAs($f['alice'])->post('/subscriptions', [
        'customer_id' => $f['customer']->getKey(),
        'subscription_plan_id' => $f['plan']->getKey(),
        'quantity' => '1',
        'starts_on' => now()->toDateString(),
        'collect_online' => true,
    ])->assertRedirect();

    // Both subscriptions are created in the same second, so latest() could return the
    // fixture's row and hide the result.
    $created = Subscription::acrossCompanies()
        ->whereKeyNot($f['subscription']->getKey())
        ->sole();

    // The field is accepted by validation but ignored without payments.create, so the
    // form cannot be used to route around the switch's own permission.
    expect($created->collect_online)->toBeFalse();
});

it('records who switched online collection on', function (): void {
    $f = linkFixture(false);

    $accountant = person($f['company'], CompanyRole::Accountant, 'ar2@acme.test', $f['branch']);
    grant($f['company'], CompanyRole::Accountant, 'payments.create', DataScope::Company);

    $this->actingAs($accountant)
        ->post("/subscriptions/{$f['subscription']->getKey()}/collect-online", ['collect_online' => true])
        ->assertRedirect();

    $logged = AuditLog::acrossCompanies()
        ->where('action', 'collect_online_enabled')
        ->where('actor_user_id', $accountant->getKey())
        ->exists();

    expect($logged)->toBeTrue();
});
