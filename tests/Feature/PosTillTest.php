<?php

declare(strict_types=1);

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Pos\PosService;
use App\Domain\Pos\TillRefused;
use App\Enums\FulfilmentStatus;
use App\Models\Attribution;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Warehouse;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

/** @return array<string, mixed> */
function tillFixture(string $onHand = '20', string $price = '25'): array
{
    $f = routeFixture();

    $extra = test()->withCompany($f['company'], function () use ($f, $onHand, $price): array {
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);

        $register = PosRegister::create([
            'branch_id' => $f['branch']->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'code' => 'TILL-1',
            'name' => 'Front counter',
        ]);

        $product = Product::create(['sku' => 'CANDY', 'name' => 'Candy bar']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'CANDY-STD',
            'name' => 'Standard',
            'selling_price' => $price,
            'cost_price' => '10',
            'is_default' => true,
        ]);

        $stock = Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_variant_id' => $variant->getKey()]);
        $stock->forceFill(['on_hand' => $onHand])->save();

        return compact('warehouse', 'register', 'variant', 'stock');
    });

    return [...$f, ...$extra];
}

function till(): PosService
{
    return app(PosService::class);
}

it('opens a session and refuses a second one on the same register', function (): void {
    $f = tillFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        expect($session->isOpen())->toBeTrue()
            ->and($session->reference)->toStartWith('TILL-')
            ->and((string) $session->opening_float)->toBe('100.0000');

        expect(fn () => till()->openSession($f['register']->refresh(), $f['bob'], '50'))
            ->toThrow(TillRefused::class, 'already has an open session');

        expect(PosSession::query()->count())->toBe(1);
    });
});

it('refuses to open a session on a register that is switched off', function (): void {
    $f = tillFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $f['register']->forceFill(['is_active' => false])->save();

        expect(fn () => till()->openSession($f['register']->refresh(), $f['alice'], '100'))
            ->toThrow(TillRefused::class, 'switched off');
    });
});

it('sells at the counter, takes the money and moves the stock in one transaction', function (): void {
    $f = tillFixture('20', '25');

    $order = $this->withCompany($f['company'], function () use ($f): Order {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        return till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );
    });

    $this->withCompany($f['company'], function () use ($f, $order): void {
        expect((string) $order->total)->toBe('50.0000')
            ->and($order->payment_status->value)->toBe('paid')
            ->and($order->fulfilment_status)->toBe(FulfilmentStatus::Completed)
            ->and($order->pos_session_id)->not->toBeNull()
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('18.0000')
            ->and((string) $f['stock']->fresh()?->reserved)->toBe('0.0000');
    });
});

it('refuses a sale that is not fully tendered', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        expect(fn () => till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '30']],
            $f['alice'],
        ))->toThrow(TillRefused::class, 'was tendered');

        expect(Order::query()->count())->toBe(0, 'a refused sale must leave nothing behind')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000');
    });
});

it('refuses a sale with no tender at all', function (): void {
    $f = tillFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        expect(fn () => till()->sell($session, [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']], [], $f['alice']))
            ->toThrow(TillRefused::class, 'paid before the goods leave');

        expect(Order::query()->count())->toBe(0);
    });
});

it('refuses to sell more than the shelf holds', function (): void {
    $f = tillFixture('3', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        expect(fn () => till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '5']],
            [['method' => 'cash', 'amount' => '125']],
            $f['alice'],
        ))->toThrow(InsufficientStock::class);

        expect(Order::query()->count())->toBe(0)
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('3.0000');
    });
});

it('refuses to sell from a closed session', function (): void {
    $f = tillFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');
        till()->closeSession($session, '100', $f['alice']);

        expect(fn () => till()->sell(
            $session->refresh(),
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
            [['method' => 'cash', 'amount' => '25']],
            $f['alice'],
        ))->toThrow(TillRefused::class, 'closed');
    });
});

it('splits a tender across two methods and never over-applies', function (): void {
    $f = tillFixture('20', '25');

    $order = $this->withCompany($f['company'], function () use ($f): Order {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        return till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '4']],
            [
                ['method' => 'card', 'amount' => '60'],
                ['method' => 'cash', 'amount' => '100'],
            ],
            $f['alice'],
        );
    });

    $this->withCompany($f['company'], function () use ($order): void {
        $payments = Payment::query()->where('order_id', $order->getKey())->get();

        expect((string) $order->total)->toBe('100.0000')
            ->and((string) $order->paid_amount)->toBe('100.0000', 'change given is not a payment')
            ->and($payments)->toHaveCount(2)
            ->and((string) $payments->firstWhere('method', 'card')?->amount)->toBe('60.0000')
            ->and((string) $payments->firstWhere('method', 'cash')?->amount)->toBe('40.0000');
    });
});

it('computes the drawer from the float, the cash taken and every till movement', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );

        till()->sell(
            $session->refresh(),
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
            [['method' => 'card', 'amount' => '25']],
            $f['alice'],
        );

        till()->recordCash($session->refresh(), 'cash_out', '20', 'Paid the window cleaner', $f['alice']);
        till()->recordCash($session->refresh(), 'cash_in', '5', 'Found under the drawer', $f['alice']);

        expect(till()->expectedCash($session->refresh())->toDecimal())
            ->toBe('135.0000', '100 float + 50 cash sale + 5 in - 20 out; the card sale is not cash');
    });
});

it('closes the drawer and records the variance rather than accepting a typed one', function (): void {
    $f = tillFixture('20', '25');

    $closed = $this->withCompany($f['company'], function () use ($f): PosSession {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );

        return till()->closeSession($session->refresh(), '145', $f['alice'], 'Short by five');
    });

    expect((string) $closed->expected_cash)->toBe('150.0000')
        ->and((string) $closed->counted_cash)->toBe('145.0000')
        ->and((string) $closed->variance)->toBe('-5.0000')
        ->and($closed->isOpen())->toBeFalse();
});

it('refuses to close a session twice', function (): void {
    $f = tillFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');
        till()->closeSession($session, '100', $f['alice']);

        expect(fn () => till()->closeSession($session->refresh(), '100', $f['alice']))
            ->toThrow(TillRefused::class, 'already closed');
    });
});

it('lets the register be reopened once the previous session is closed', function (): void {
    $f = tillFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $first = till()->openSession($f['register'], $f['alice'], '100');
        till()->closeSession($first, '100', $f['alice']);

        $second = till()->openSession($f['register']->refresh(), $f['bob'], '80');

        expect($second->isOpen())->toBeTrue()
            ->and(PosSession::query()->count())->toBe(2);
    });
});

it('refuses to edit a till movement, in the model and in the database', function (): void {
    $f = tillFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');
        $movement = till()->recordCash($session, 'cash_out', '20', 'Petty cash', $f['alice']);

        expect(fn () => $movement->update(['amount' => '5']))
            ->toThrow(RuntimeException::class, 'append-only');

        expect(fn () => DB::table('pos_cash_movements')
            ->where('id', $movement->getKey())
            ->update(['amount' => '5']))
            ->toThrow(QueryException::class, 'never edited');
    });
});

it('puts a counter sale into the same reports as any other order', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );

        $attribution = Attribution::query()->firstOrFail();

        expect(Order::query()->count())->toBe(1)
            ->and($attribution->salesperson_user_id)->toBe($f['alice']->getKey(), 'the cashier is the closer')
            ->and(OrderEvent::query()->where('event', 'order.created')->count())->toBe(1);
    });
});

it('sells from the register warehouse, not the branch default', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $f['warehouse']->forceFill(['branch_id' => $f['branch']->getKey()])->save();

        $counterStock = Warehouse::create(['code' => 'COUNTER', 'name' => 'Counter shelf']);

        $shelf = Stock::create(['warehouse_id' => $counterStock->getKey(), 'product_variant_id' => $f['variant']->getKey()]);
        $shelf->forceFill(['on_hand' => '9'])->save();

        $f['register']->forceFill(['warehouse_id' => $counterStock->getKey()])->save();

        $session = till()->openSession($f['register']->refresh(), $f['alice'], '0');

        till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '3']],
            [['method' => 'cash', 'amount' => '75']],
            $f['alice'],
        );

        expect((string) $shelf->fresh()?->on_hand)->toBe('6.0000', 'the counter shelf is what was sold from')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe(
                '20.0000',
                'the branch warehouse is what the ordinary lookup would have chosen, so it must be untouched'
            );
    });
});

it('leaves nothing behind when a sale fails after the order is written', function (): void {
    $f = tillFixture('1', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        expect(fn () => till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        ))->toThrow(InsufficientStock::class);

        expect(Order::query()->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0)
            ->and(PosCashMovement::query()->count())->toBe(0)
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('1.0000');
    });
});
