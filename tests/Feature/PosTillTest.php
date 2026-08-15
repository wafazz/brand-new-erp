<?php

declare(strict_types=1);

use App\Domain\Commission\CommissionEngine;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Pos\PosService;
use App\Domain\Pos\TillRefused;
use App\Domain\Reporting\RollupService;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Attribution;
use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesRollup;
use App\Models\Stock;
use App\Models\Warehouse;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

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

it('refunds a counter sale: stock back, money out, commission reversed', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $plan = CommissionPlan::create([
            'code' => 'STD', 'name' => 'Standard', 'strategy' => 'percentage_of_value', 'recipient_role' => 'salesperson',
        ]);
        $rule = CommissionRule::create([
            'commission_plan_id' => $plan->getKey(), 'code' => 'BASE', 'name' => 'Base',
        ]);
        CommissionRuleVersion::create([
            'commission_rule_id' => $rule->getKey(), 'version' => 1, 'rate_type' => 'percent',
            'rate_value' => '10', 'valid_from' => now()->subDay(),
        ]);

        $session = till()->openSession($f['register'], $f['alice'], '100');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );

        app(CommissionEngine::class)->accrueForOrder($sale->refresh(), $f['alice']);

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('18.0000')
            ->and(till()->expectedCash($session->refresh())->toDecimal())->toBe('150.0000')
            ->and(Commission::query()->where('status', 'pending')->count())->toBe(1);

        $refunded = till()->refund($session->refresh(), $sale->refresh(), 'Wrong size', $f['alice']);

        expect($refunded->exception_status)->toBe(ExceptionStatus::Returned)
            ->and($refunded->payment_status)->toBe(PaymentStatus::Refunded)
            ->and((string) $refunded->paid_amount)->toBe('0.0000')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000', 'the goods are back on the shelf')
            ->and(till()->expectedCash($session->refresh())->toDecimal())->toBe('100.0000', 'the cash left the drawer')
            ->and(Commission::query()->where('type', 'reversal')->count())->toBe(1)
            ->and(Commission::query()->where('status', 'reversed')->count())->toBe(1);
    });
});

it('refuses to refund the same sale twice', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
            [['method' => 'cash', 'amount' => '25']],
            $f['alice'],
        );

        till()->refund($session->refresh(), $sale->refresh(), 'Changed their mind', $f['alice']);

        expect(fn () => till()->refund($session->refresh(), $sale->refresh(), 'Again', $f['alice']))
            ->toThrow(TillRefused::class, 'already been refunded');

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000', 'stock must not come back twice');
    });
});

it('refuses a refund with no reason', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
            [['method' => 'cash', 'amount' => '25']],
            $f['alice'],
        );

        expect(fn () => till()->refund($session->refresh(), $sale->refresh(), '   ', $f['alice']))
            ->toThrow(TillRefused::class, 'needs a reason');

        expect($sale->fresh()?->exception_status->value)->toBe('none');
    });
});

it('refuses to refund a sale that was never taken at a till', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '100');

        $webOrder = Order::create([
            'order_number' => 'SO-WEB', 'customer_name' => 'Online buyer', 'placed_at' => now(),
        ]);

        expect(fn () => till()->refund($session, $webOrder, 'Wrong item', $f['alice']))
            ->toThrow(TillRefused::class, 'not taken at a till');
    });
});

it('refunds each tender back to the method it came in on', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '4']],
            [['method' => 'card', 'amount' => '60'], ['method' => 'cash', 'amount' => '40']],
            $f['alice'],
        );

        till()->refund($session->refresh(), $sale->refresh(), 'Faulty', $f['alice']);

        $refunds = Payment::query()->where('order_id', $sale->getKey())->where('amount', '<', 0)->get();

        expect($refunds)->toHaveCount(2)
            ->and((string) $refunds->firstWhere('method', 'card')?->amount)->toBe('-60.0000')
            ->and((string) $refunds->firstWhere('method', 'cash')?->amount)->toBe('-40.0000')
            ->and(till()->expectedCash($session->refresh())->toDecimal())->toBe('0.0000', 'only the cash half leaves the drawer');
    });
});

it('returns one line of three and leaves the rest with the customer', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '3']],
            [['method' => 'cash', 'amount' => '75']],
            $f['alice'],
        );

        $item = $sale->items()->firstOrFail();

        $after = till()->refund(
            $session->refresh(),
            $sale->refresh(),
            'One was damaged',
            $f['alice'],
            [['order_item_id' => $item->getKey(), 'quantity' => '1']],
        );

        expect((string) $after->returned_amount)->toBe('25.0000')
            ->and((string) $item->fresh()?->quantity_returned)->toBe('1.0000')
            ->and($after->exception_status->value)->toBe('none', 'two of three are still with the customer')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('18.0000', 'only one came back')
            ->and($after->outstanding()->toDecimal())->toBe('0.0000', 'a part return is not a debt')
            ->and($after->keptTotal()->toDecimal())->toBe('50.0000')
            ->and(till()->expectedCash($session->refresh())->toDecimal())->toBe('50.0000');
    });
});

it('marks the sale returned only once the last item comes back', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );

        $item = $sale->items()->firstOrFail();

        till()->refund($session->refresh(), $sale->refresh(), 'First one', $f['alice'], [
            ['order_item_id' => $item->getKey(), 'quantity' => '1'],
        ]);

        expect($sale->fresh()?->exception_status->value)->toBe('none');

        $final = till()->refund($session->refresh(), $sale->refresh(), 'Second one', $f['alice'], [
            ['order_item_id' => $item->getKey(), 'quantity' => '1'],
        ]);

        expect($final->exception_status->value)->toBe('returned')
            ->and((string) $final->returned_amount)->toBe('50.0000')
            ->and((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000');
    });
});

it('refuses to take back more than the customer still has', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );

        $item = $sale->items()->firstOrFail();

        expect(fn () => till()->refund($session->refresh(), $sale->refresh(), 'Greedy', $f['alice'], [
            ['order_item_id' => $item->getKey(), 'quantity' => '5'],
        ]))->toThrow(TillRefused::class, 'still with the customer');

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('18.0000');
    });
});

it('adjusts commission in proportion to what came back', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $plan = CommissionPlan::create([
            'code' => 'STD', 'name' => 'Standard', 'strategy' => 'percentage_of_value', 'recipient_role' => 'salesperson',
        ]);
        $rule = CommissionRule::create(['commission_plan_id' => $plan->getKey(), 'code' => 'B', 'name' => 'Base']);
        CommissionRuleVersion::create([
            'commission_rule_id' => $rule->getKey(), 'version' => 1, 'rate_type' => 'percent',
            'rate_value' => '10', 'valid_from' => now()->subDay(),
        ]);

        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '4']],
            [['method' => 'cash', 'amount' => '100']],
            $f['alice'],
        );

        app(CommissionEngine::class)->accrueForOrder($sale->refresh(), $f['alice']);

        $earned = Commission::query()->where('type', 'direct')->firstOrFail();

        expect((string) $earned->amount)->toBe('10.0000', '10% of 100');

        $item = $sale->items()->firstOrFail();

        till()->refund($session->refresh(), $sale->refresh(), 'One faulty', $f['alice'], [
            ['order_item_id' => $item->getKey(), 'quantity' => '1'],
        ]);

        $adjustment = Commission::query()->where('type', 'adjustment')->firstOrFail();

        expect((string) $adjustment->amount)->toBe('-2.5000', 'a quarter of the sale came back, so a quarter of the commission goes')
            ->and($earned->fresh()?->status)->not->toBe('reversed', 'the original stands; only the difference is adjusted');
    });
});

it('keeps a returned sale out of revenue', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '4']],
            [['method' => 'cash', 'amount' => '100']],
            $f['alice'],
        );

        app(RollupService::class)->rebuildSales(now());

        expect((string) SalesRollup::query()->sum('revenue'))->toBe('100.0000');

        $item = $sale->items()->firstOrFail();

        till()->refund($session->refresh(), $sale->refresh(), 'Two faulty', $f['alice'], [
            ['order_item_id' => $item->getKey(), 'quantity' => '2'],
        ]);

        app(RollupService::class)->rebuildSales(now());

        expect((string) SalesRollup::query()->sum('revenue'))
            ->toBe('50.0000', 'a refunded half must not still count as revenue');
    });
});

it('refuses a cashier refunding a sale from another session', function (): void {
    $f = tillFixture('20', '25');

    $sale = $this->withCompany($f['company'], function () use ($f): Order {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
            [['method' => 'cash', 'amount' => '25']],
            $f['alice'],
        );

        till()->closeSession($session->refresh(), '25', $f['alice']);

        return $sale;
    });

    $this->withCompany($f['company'], function () use ($f, $sale): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['alice']->can('pos.sell'))->toBeTrue('the cashier can sell')
            ->and($f['alice']->can('pos.manage'))->toBeFalse('but is not a supervisor');

        $today = till()->openSession($f['register']->refresh(), $f['alice'], '0');

        expect(fn () => till()->refund($today, $sale->refresh(), 'Yesterday was a mistake', $f['alice']))
            ->toThrow(TillRefused::class, 'different till session');

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('19.0000', 'the goods stayed sold');
    });
});

it('lets a supervisor refund a sale from an earlier session', function (): void {
    $f = tillFixture('20', '25');

    $sale = $this->withCompany($f['company'], function () use ($f): Order {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '1']],
            [['method' => 'cash', 'amount' => '25']],
            $f['alice'],
        );

        till()->closeSession($session->refresh(), '25', $f['alice']);

        return $sale;
    });

    $this->withCompany($f['company'], function () use ($f, $sale): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['owner']->can('pos.manage'))->toBeTrue('an owner supervises the till');

        $today = till()->openSession($f['register']->refresh(), $f['owner'], '0');

        till()->refund($today, $sale->refresh(), 'Customer came back the next day', $f['owner']);

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000');
    });
});

it('stops a cashier refunding more than the register allows', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $f['register']->forceFill(['refund_limit' => '30'])->save();

        $session = till()->openSession($f['register']->refresh(), $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '4']],
            [['method' => 'cash', 'amount' => '100']],
            $f['alice'],
        );

        expect(fn () => till()->refund($session->refresh(), $sale->refresh(), 'All of it', $f['alice']))
            ->toThrow(TillRefused::class, 'A supervisor has to take it');

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('16.0000', 'nothing came back');

        $item = $sale->items()->firstOrFail();

        till()->refund($session->refresh(), $sale->refresh(), 'Just one', $f['alice'], [
            ['order_item_id' => $item->getKey(), 'quantity' => '1'],
        ]);

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('17.0000', 'a refund inside the limit is fine');
    });
});

it('lets a supervisor exceed the register refund limit', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $f['register']->forceFill(['refund_limit' => '30'])->save();

        $session = till()->openSession($f['register']->refresh(), $f['owner'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '4']],
            [['method' => 'cash', 'amount' => '100']],
            $f['owner'],
        );

        till()->refund($session->refresh(), $sale->refresh(), 'Whole lot faulty', $f['owner']);

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000');
    });
});

it('puts stock back when a return is recorded away from the till', function (): void {
    $f = tillFixture('20', '25');

    $sale = $this->withCompany($f['company'], function () use ($f): Order {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        return till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '3']],
            [['method' => 'cash', 'amount' => '75']],
            $f['alice'],
        );
    });

    $this->withCompany($f['company'], function () use ($f, $sale): void {
        expect((string) $f['stock']->fresh()?->on_hand)->toBe('17.0000');

        app(OrderStateMachine::class)
            ->transition($sale->refresh(), ExceptionStatus::Returned, $f['owner'], 'Returned by post');

        $item = $sale->items()->firstOrFail();

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000', 'the order screen must move stock too')
            ->and((string) $item->fresh()?->quantity_returned)->toBe('3.0000')
            ->and((string) $sale->fresh()?->returned_amount)->toBe('75.0000');
    });
});

it('does not put stock back twice when the till already returned it', function (): void {
    $f = tillFixture('20', '25');

    $this->withCompany($f['company'], function () use ($f): void {
        $session = till()->openSession($f['register'], $f['alice'], '0');

        $sale = till()->sell(
            $session,
            [['variant_id' => $f['variant']->getKey(), 'quantity' => '2']],
            [['method' => 'cash', 'amount' => '50']],
            $f['alice'],
        );

        till()->refund($session->refresh(), $sale->refresh(), 'Faulty', $f['alice']);

        expect((string) $f['stock']->fresh()?->on_hand)->toBe('20.0000')
            ->and((string) $sale->fresh()?->returned_amount)->toBe('50.0000', 'counted once, not twice');
    });
});
