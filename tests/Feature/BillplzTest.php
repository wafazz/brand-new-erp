<?php

declare(strict_types=1);

use App\Domain\Payments\PaymentLinkService;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Support\CompanyContext;
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
});

function billplzInvoice(Company $company, User $owner, string $number, string $total = '300'): Invoice
{
    return test()->withCompany($company, function () use ($owner, $number, $total): Invoice {
        $order = Order::create([
            'order_number' => 'SO-'.$number,
            'owner_user_id' => $owner->getKey(),
            'customer_name' => 'Buyer',
            'placed_at' => now(),
        ]);

        $order->forceFill(['subtotal' => $total, 'total' => $total])->save();

        $invoice = Invoice::create([
            'order_id' => $order->getKey(),
            'issued_by' => $owner->getKey(),
            'invoice_number' => $number,
            'status' => 'issued',
            'customer_name' => 'Buyer',
            'issued_at' => now(),
            'due_at' => now()->addDays(30),
        ]);

        $invoice->forceFill(['subtotal' => $total, 'total' => $total])->save();

        return $invoice->refresh();
    });
}

/** @param array<string, mixed> $params */
function signCallback(array $params): array
{
    $source = $params;
    ksort($source);

    $parts = [];

    foreach ($source as $key => $value) {
        $parts[] = $key.$value;
    }

    return [...$params, 'x_signature' => hash_hmac('sha256', implode('|', $parts), 'test-signature-key')];
}

function fakeBillplz(string $id = 'bill-abc'): void
{
    Http::fake([
        '*/api/v3/bills' => Http::response(['id' => $id, 'url' => "https://www.billplz-sandbox.com/bills/{$id}"], 200),
    ]);
}

it('raises a Billplz bill for what is outstanding and stores the link', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B1', '450');

    $intent = $this->withCompany($f['company'], fn (): PaymentIntent => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    expect($intent->provider_ref)->toBe('bill-abc')
        ->and($intent->pay_url)->toContain('billplz-sandbox.com')
        ->and($intent->status)->toBe('pending')
        ->and((string) $intent->amount)->toBe('450.0000');

    Http::assertSent(function ($request): bool {
        // Billplz takes cents, so RM450.00 must leave here as 45000, not 450.
        return $request['amount'] === 45000 && $request['collection_id'] === 'test-collection';
    });
});

it('settles the invoice when Billplz confirms payment', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B2', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc',
        'collection_id' => 'test-collection',
        'paid' => 'true',
        'state' => 'paid',
        'amount' => '45000',
        'paid_amount' => '45000',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect($fresh->status)->toBe('paid')
        ->and((string) $fresh->paid_amount)->toBe('450.0000');
});

it('does not pay the invoice twice when Billplz repeats the callback', function (): void {
    $f = routeFixture();
    fakeBillplz();

    // Bill only part of a larger invoice. If the invoice were billed in full, a replay
    // would be stopped by the clamp to what is outstanding rather than by the replay
    // guard, and this test would pass with that guard deleted.
    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B3', '900');

    $intent = $this->withCompany($f['company'], function () use ($invoice, $f): PaymentIntent {
        $intent = app(PaymentLinkService::class)->createFor($invoice, $f['owner']);
        $intent->forceFill(['amount' => '450'])->save();

        return $intent;
    });

    expect((string) $intent->amount)->toBe('450.0000');

    $payload = signCallback(['id' => 'bill-abc', 'paid' => 'true', 'paid_amount' => '45000']);

    $this->post('/payments/billplz/callback', $payload)->assertOk();
    $this->post('/payments/billplz/callback', $payload)->assertOk();
    $this->post('/payments/billplz/callback', $payload)->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    // One payment of 450 against a 900 invoice. Three callbacks must not make it 1350.
    expect((string) $fresh->paid_amount)->toBe('450.0000')
        ->and($fresh->status)->toBe('partially_paid');
});

it('records the amount we billed, not a larger amount the callback claims', function (): void {
    $f = routeFixture();
    fakeBillplz();

    // Again a partial bill against a larger invoice, so that an inflated claim stays
    // below what is outstanding and the clamp cannot do this guard's work for it.
    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B4', '900');

    $this->withCompany($f['company'], function () use ($invoice, $f): void {
        app(PaymentLinkService::class)->createFor($invoice, $f['owner'])
            ->forceFill(['amount' => '450'])->save();
    });

    // Correctly signed, and well within the invoice — but more than we ever billed.
    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc', 'paid' => 'true', 'paid_amount' => '80000',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect((string) $fresh->paid_amount)->toBe('450.0000');
});

it('credits only what the callback says was collected when that is less than the bill', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B4b', '900');

    $this->withCompany($f['company'], function () use ($invoice, $f): void {
        app(PaymentLinkService::class)->createFor($invoice, $f['owner'])
            ->forceFill(['amount' => '450'])->save();
    });

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc', 'paid' => 'true', 'paid_amount' => '10000',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    // Crediting the 450 we billed would hand the customer 350 they never paid.
    expect((string) $fresh->paid_amount)->toBe('100.0000');
});

it('refuses a callback signed with the wrong key', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B5', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $params = ['id' => 'bill-abc', 'paid' => 'true', 'amount' => '45000'];
    ksort($params);
    $source = implode('|', array_map(fn ($k, $v): string => $k.$v, array_keys($params), $params));

    $this->post('/payments/billplz/callback', [
        ...$params,
        'x_signature' => hash_hmac('sha256', $source, 'a-key-billplz-never-issued'),
    ])->assertForbidden();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect((string) $fresh->paid_amount)->toBe('0.0000')
        ->and($fresh->status)->toBe('issued');
});

it('refuses a callback whose parameters were altered after signing', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B6', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $signed = signCallback(['id' => 'bill-abc', 'paid' => 'false', 'amount' => '45000']);

    // Flip the one field that decides whether money moved.
    $signed['paid'] = 'true';

    $this->post('/payments/billplz/callback', $signed)->assertForbidden();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect((string) $fresh->paid_amount)->toBe('0.0000');
});

it('leaves the invoice alone when Billplz reports the payment failed', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B7', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc', 'paid' => 'false', 'state' => 'due', 'amount' => '45000',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect($fresh->status)->toBe('issued')
        ->and((string) $fresh->paid_amount)->toBe('0.0000');
});

it('verifies the signature before it looks for the bill', function (): void {
    // An unsigned callback naming a bill that does not exist must be refused for the
    // signature, not merely lost. Otherwise the 403 would be hiding behind a lookup.
    $this->post('/payments/billplz/callback', ['id' => 'no-such-bill', 'paid' => 'true'])
        ->assertForbidden();
});

it('never settles anything from the browser redirect', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B8', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $params = ['id' => 'bill-abc', 'paid' => 'true', 'paid_at' => '2026-08-16 10:00:00'];
    $flat = [];

    foreach ($params as $key => $value) {
        $flat['billplz'.$key] = $value;
    }

    ksort($flat);
    $source = implode('|', array_map(fn ($k, $v): string => $k.$v, array_keys($flat), $flat));

    $this->get('/payments/billplz/return?'.http_build_query([
        'billplz' => [...$params, 'x_signature' => hash_hmac('sha256', $source, 'test-signature-key')],
    ]))->assertRedirect();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    // The redirect is under the payer's control. Only the server-to-server callback pays.
    expect((string) $fresh->paid_amount)->toBe('0.0000')
        ->and($fresh->status)->toBe('issued');
});

it('settles into the right company when no session names one', function (): void {
    $f = routeFixture();
    fakeBillplz('bill-one');

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-B9', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    app(CompanyContext::class)->forget();

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-one', 'paid' => 'true', 'amount' => '45000',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect($fresh->status)->toBe('paid');
});

it('refuses a payment link to a role without payments.create', function (): void {
    $f = routeFixture();
    fakeBillplz();

    grant($f['company'], CompanyRole::Salesperson, 'invoices.view', DataScope::Company);

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BA', '450');

    $this->actingAs($f['alice'])
        ->post("/invoices/{$invoice->getKey()}/payment-link")
        ->assertForbidden();

    expect(PaymentIntent::acrossCompanies()->count())->toBe(0);
});

it('lets an accountant raise a payment link', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $accountant = person($f['company'], CompanyRole::Accountant, 'ap@acme.test', $f['branch']);
    grant($f['company'], CompanyRole::Accountant, 'invoices.view', DataScope::Company);
    grant($f['company'], CompanyRole::Accountant, 'payments.create', DataScope::Company);

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BB', '450');

    $this->actingAs($accountant)
        ->post("/invoices/{$invoice->getKey()}/payment-link")
        ->assertRedirect();

    expect(PaymentIntent::acrossCompanies()->count())->toBe(1);
});

it('hands back the same link rather than raising a second bill for the same amount', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BC', '450');

    $first = $this->withCompany($f['company'], fn (): PaymentIntent => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));
    $second = $this->withCompany($f['company'], fn (): PaymentIntent => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    expect($second->getKey())->toBe($first->getKey());

    Http::assertSentCount(1);
});

it('refuses to bill an invoice with nothing outstanding', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BD', '450');
    $this->withCompany($f['company'], fn () => $invoice->forceFill(['paid_amount' => '450', 'status' => 'paid'])->save());

    $this->withCompany($f['company'], function () use ($invoice): void {
        expect(fn () => app(PaymentLinkService::class)->createFor($invoice->refresh()))
            ->toThrow(RuntimeException::class, 'nothing outstanding');
    });
});

it('cannot be talked into settling another bill by repartitioning the signed string', function (): void {
    $f = routeFixture();
    fakeBillplz('bill-victim');

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BE', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    // The source string is "key1value1|key2value2" with nothing escaping the boundary,
    // so {ab: 'c'} and {a: 'bc'} sign identically. Prove that ambiguity cannot be aimed
    // at a bill the sender does not already hold a genuine callback for.
    $genuine = signCallback(['id' => 'bill-other', 'paid' => 'true']);

    $this->post('/payments/billplz/callback', [
        'i' => 'dbill-victim',
        'paid' => 'true',
        'x_signature' => $genuine['x_signature'],
    ])->assertForbidden();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect((string) $fresh->paid_amount)->toBe('0.0000');
});

it('refuses a callback that hides a parameter inside an array', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BF', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    // Array values are skipped when the source string is built. Sending one must shorten
    // the source and break the signature, never quietly drop a field from the check.
    $signed = signCallback(['id' => 'bill-abc', 'paid' => 'false']);

    $this->post('/payments/billplz/callback', [
        'id' => 'bill-abc',
        'paid' => ['false'],
        'x_signature' => $signed['x_signature'],
    ])->assertForbidden();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect((string) $fresh->paid_amount)->toBe('0.0000');
});

it('refuses a signature sent as an array rather than a string', function (): void {
    $this->post('/payments/billplz/callback', [
        'id' => 'bill-abc',
        'paid' => 'true',
        'x_signature' => ['a', 'b'],
    ])->assertForbidden();
});

it('ignores a paid_amount that is not a number', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BG', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc', 'paid' => 'true', 'paid_amount' => 'not-a-number',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    // Falls back to the amount we billed rather than crediting zero or crashing.
    expect((string) $fresh->paid_amount)->toBe('450.0000');
});

it('never credits a negative amount from a callback', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BH', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc', 'paid' => 'true', 'paid_amount' => '-45000',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect((string) $fresh->paid_amount)->toBe('0.0000')
        ->and($fresh->status)->toBe('issued');
});

it('does not mark the intent settled when nothing was credited', function (): void {
    $f = routeFixture();
    fakeBillplz();

    $invoice = billplzInvoice($f['company'], $f['owner'], 'INV-BI', '450');
    $this->withCompany($f['company'], fn () => app(PaymentLinkService::class)->createFor($invoice, $f['owner']));

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc', 'paid' => 'true', 'paid_amount' => '-45000',
    ]))->assertOk();

    $intent = PaymentIntent::acrossCompanies()->where('provider_ref', 'bill-abc')->firstOrFail();

    // Marking it paid here would make the genuine callback that follows look like a
    // replay, and the real payment would be swallowed in silence.
    expect($intent->status)->toBe('pending');

    $this->post('/payments/billplz/callback', signCallback([
        'id' => 'bill-abc', 'paid' => 'true', 'paid_amount' => '45000',
    ]))->assertOk();

    $fresh = $this->withCompany($f['company'], fn (): Invoice => Invoice::query()->findOrFail($invoice->getKey()));

    expect((string) $fresh->paid_amount)->toBe('450.0000');
});
