<?php

declare(strict_types=1);

use App\Domain\Pos\PosService;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Order;
use App\Models\PosCashMovement;
use App\Models\PosSession;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

it('refuses the till to a role without pos.view', function (): void {
    $f = tillFixture();

    $purchaser = person($f['company'], CompanyRole::Purchaser, 'buyer@acme.test', $f['branch']);

    $this->actingAs($purchaser)->get('/dashboard')->assertOk();
    $this->actingAs($purchaser)->get('/pos')->assertForbidden();
});

it('lets an accountant watch the tills but never sell', function (): void {
    $f = tillFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $accountant): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($accountant->can('pos.view'))->toBeTrue('an accountant reconciles the takings')
            ->and($accountant->can('pos.sell'))->toBeFalse('but never stands at the counter');
    });

    $this->actingAs($accountant)
        ->get('/pos')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Pos/Index')->where('can.sell', false));

    $this->actingAs($accountant)
        ->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '100'])
        ->assertForbidden();

    expect($this->withCompany($f['company'], fn (): int => PosSession::query()->count()))->toBe(0);
});

it('opens a till over HTTP and lands the cashier at the counter', function (): void {
    $f = tillFixture();

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '150'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    expect($session->opened_by)->toBe($f['alice']->getKey())
        ->and((string) $session->opening_float)->toBe('150.0000');

    $this->actingAs($f['alice'])
        ->get("/pos/{$session->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pos/Session')
            ->where('session.reference', $session->reference)
            ->where('session.expected_cash', '150.0000')
            ->has('variants'));
});

it('takes a counter sale over HTTP and shows the change on the drawer', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])
        ->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0'])
        ->assertRedirect();

    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
        'tenders' => [['method' => 'cash', 'amount' => '100']],
    ])->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($f, $session): void {
        $order = Order::query()->firstOrFail();

        expect((string) $order->total)->toBe('50.0000')
            ->and((string) $order->paid_amount)->toBe('50.0000', 'change is not takings')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('18.0000')
            ->and(app(PosService::class)->expectedCash($session->refresh())->toDecimal())->toBe('50.0000');
    });
});

it('refuses a short tender at the endpoint, not just in the browser', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0']);

    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
        'tenders' => [['method' => 'cash', 'amount' => '10']],
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($f): void {
        expect(Order::query()->count())->toBe(0)
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000');
    });
});

it('refuses to sell from a till the cashier cannot reach', function (): void {
    $f = tillFixture();

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $session = $this->withCompany(
        $f['company'],
        fn (): PosSession => app(PosService::class)->openSession($f['register'], $f['bob'], '100')
    );

    $this->actingAs($f['alice'])->get("/pos/{$session->getKey()}")->assertForbidden();

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
        'tenders' => [['method' => 'cash', 'amount' => '25']],
    ])->assertForbidden();

    $this->withCompany($f['company'], function (): void {
        expect(Order::query()->count())->toBe(0);
    });
});

it('lets an owner see every till while a cashier sees only their own', function (): void {
    $f = tillFixture();

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'pos.view', DataScope::Company);

    $this->withCompany($f['company'], function () use ($f): void {
        app(PosService::class)->openSession($f['register'], $f['bob'], '100');
    });

    $this->actingAs($f['alice'])
        ->get('/pos')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('recent', 0));

    $this->actingAs($f['owner'])
        ->get('/pos')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('recent', 1));
});

it('refuses a till movement with no reason', function (): void {
    $f = tillFixture();

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '100']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])
        ->post("/pos/{$session->getKey()}/cash", ['kind' => 'cash_out', 'amount' => '20', 'reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->withCompany($f['company'], function (): void {
        expect(PosCashMovement::query()->count())->toBe(0);
    });
});

it('closes the till over HTTP and reports the variance in the flash', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '100']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
        'tenders' => [['method' => 'cash', 'amount' => '50']],
    ])->assertSessionMissing('error');

    $this->actingAs($f['alice'])
        ->post("/pos/{$session->getKey()}/close", ['counted_cash' => '148'])
        ->assertRedirect('/pos')
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '-2.00'));

    $this->withCompany($f['company'], function () use ($session): void {
        expect((string) $session->fresh()?->variance)->toBe('-2.0000')
            ->and($session->fresh()?->status)->toBe('closed');
    });
});

it('prints a receipt for a counter sale and refuses one for an order that never saw a till', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
        'tenders' => [['method' => 'cash', 'amount' => '100']],
    ])->assertSessionMissing('error');

    $sale = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    $this->actingAs($f['alice'])
        ->get("/pos/receipt/{$sale->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pos/Receipt')
            ->where('receipt.total', '50.0000')
            ->where('receipt.refunded', false)
            ->has('lines', 1)
            ->has('tenders', 1));

    $webOrder = $this->withCompany($f['company'], fn (): Order => Order::create([
        'order_number' => 'SO-WEB', 'customer_name' => 'Online', 'placed_at' => now(),
    ]));

    $this->actingAs($f['alice'])->get("/pos/receipt/{$webOrder->getKey()}")->assertNotFound();
});

it('refunds a sale over HTTP and marks the receipt refunded', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
        'tenders' => [['method' => 'cash', 'amount' => '50']],
    ])->assertSessionMissing('error');

    $sale = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    $this->actingAs($f['alice'])
        ->post("/pos/{$session->getKey()}/refund", ['order_id' => $sale->getKey(), 'reason' => 'Wrong flavour'])
        ->assertRedirect()
        ->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($f, $sale): void {
        expect($sale->fresh()?->exception_status->value)->toBe('returned')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000');
    });

    $this->actingAs($f['alice'])
        ->get("/pos/receipt/{$sale->getKey()}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.refunded', true)->has('tenders', 2));
});

it('refuses a refund with no reason at the endpoint', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
        'tenders' => [['method' => 'cash', 'amount' => '25']],
    ]);

    $sale = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    $this->actingAs($f['alice'])
        ->post("/pos/{$session->getKey()}/refund", ['order_id' => $sale->getKey(), 'reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->withCompany($f['company'], function () use ($sale): void {
        expect($sale->fresh()?->exception_status->value)->toBe('none');
    });
});

it('refuses a refund from a role that may only watch', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
        'tenders' => [['method' => 'cash', 'amount' => '25']],
    ]);

    $sale = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());

    $accountant = person($f['company'], CompanyRole::Accountant, 'books2@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::Accountant, 'pos.view', DataScope::Company);

    $this->actingAs($accountant)
        ->post("/pos/{$session->getKey()}/refund", ['order_id' => $sale->getKey(), 'reason' => 'Trying it on'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($f, $sale): void {
        expect($sale->fresh()?->exception_status->value)->toBe('none')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('19.0000');
    });
});

it('takes a part return over HTTP and leaves the rest with the customer', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '3']],
        'tenders' => [['method' => 'cash', 'amount' => '75']],
    ])->assertSessionMissing('error');

    $sale = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());
    $item = $this->withCompany($f['company'], fn () => $sale->items()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/refund", [
        'order_id' => $sale->getKey(),
        'reason' => 'One was damaged',
        'lines' => [['order_item_id' => $item->getKey(), 'quantity' => '1']],
    ])->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($f, $sale): void {
        expect((string) $sale->fresh()?->returned_amount)->toBe('25.0000')
            ->and($sale->fresh()?->exception_status->value)->toBe('none')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('18.0000');
    });

    $this->actingAs($f['alice'])
        ->get("/pos/{$session->getKey()}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sales.0.returned_amount', '25.0000')
            ->where('sales.0.refunded', false)
            ->has('sales.0.lines', 1)
            ->where('sales.0.lines.0.outstanding', '2.0000'));
});

it('refuses a part return larger than what is left', function (): void {
    $f = tillFixture('20', '25');

    grant($f['company'], CompanyRole::Salesperson, 'pos.view', DataScope::Own);

    $this->actingAs($f['alice'])->post('/pos/open', ['pos_register_id' => $f['register']->getKey(), 'opening_float' => '0']);
    $session = $this->withCompany($f['company'], fn (): PosSession => PosSession::query()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/sell", [
        'lines' => [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
        'tenders' => [['method' => 'cash', 'amount' => '50']],
    ]);

    $sale = $this->withCompany($f['company'], fn (): Order => Order::query()->firstOrFail());
    $item = $this->withCompany($f['company'], fn () => $sale->items()->firstOrFail());

    $this->actingAs($f['alice'])->post("/pos/{$session->getKey()}/refund", [
        'order_id' => $sale->getKey(),
        'reason' => 'Trying it on',
        'lines' => [['order_item_id' => $item->getKey(), 'quantity' => '9']],
    ])->assertRedirect()->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($f, $sale): void {
        expect((string) $sale->fresh()?->returned_amount)->toBe('0.0000')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('18.0000');
    });
});
