<?php

declare(strict_types=1);

namespace App\Domain\Pos;

use App\Domain\Commission\CommissionEngine;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Domain\Numbering\DocumentNumberService;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PosService
{
    private const COUNTER_PATH = [
        FulfilmentStatus::Pending,
        FulfilmentStatus::Approved,
        FulfilmentStatus::Allocated,
        FulfilmentStatus::Picked,
        FulfilmentStatus::Packed,
        FulfilmentStatus::Shipped,
        FulfilmentStatus::Delivered,
        FulfilmentStatus::Completed,
    ];

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderStateMachine $states,
        private readonly DocumentNumberService $numbers,
        private readonly InventoryService $inventory,
        private readonly CommissionEngine $commissions,
    ) {}

    public function openSession(PosRegister $register, User $cashier, string $openingFloat): PosSession
    {
        if (! $register->is_active) {
            throw new TillRefused("Register {$register->name} is switched off.");
        }

        if (bccomp($openingFloat, '0', 4) === -1) {
            throw new TillRefused('An opening float cannot be negative.');
        }

        return DB::transaction(function () use ($register, $cashier, $openingFloat): PosSession {
            if ($register->openSession() !== null) {
                throw new TillRefused(
                    "Register {$register->name} already has an open session. Close it before opening another."
                );
            }

            return PosSession::create([
                'pos_register_id' => $register->getKey(),
                'opened_by' => $cashier->getKey(),
                'reference' => $this->numbers->next('pos_session', 'TILL'),
                'status' => 'open',
                'opening_float' => $openingFloat,
                'opened_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<int, array{variant_id: string, quantity: string}>  $lines
     * @param  array<int, array{method: string, amount: string, reference?: ?string}>  $tenders
     */
    public function sell(PosSession $session, array $lines, array $tenders, User $cashier, ?string $customerId = null): Order
    {
        if (! $session->isOpen()) {
            throw new TillRefused('This till session is closed. Open a new one before selling.');
        }

        if ($lines === []) {
            throw new TillRefused('A sale needs at least one line.');
        }

        if ($tenders === []) {
            throw new TillRefused('A counter sale is paid before the goods leave. Take payment first.');
        }

        $register = $session->register;

        return DB::transaction(function () use ($session, $register, $lines, $tenders, $cashier, $customerId): Order {
            $order = $this->orders->create([
                'customer_id' => $customerId,
                'branch_id' => $register?->branch_id,
                'lines' => $lines,
            ], $cashier);

            $order->forceFill(['pos_session_id' => $session->getKey()])->save();

            $due = Money::of((string) $order->total, $order->currency);
            $taken = Money::zero($order->currency);

            foreach ($tenders as $tender) {
                $taken = $taken->plus(Money::of($tender['amount'], $order->currency));
            }

            if ($taken->lessThan($due)) {
                throw new TillRefused(
                    "This sale comes to {$due->format()} and {$taken->format()} was tendered."
                );
            }

            $this->takePayment($order, $tenders, $due, $cashier);
            $this->walkToCompleted($order->refresh(), $cashier);

            return $order->refresh();
        });
    }

    /**
     * @param  array<int, array{order_item_id: string, quantity: string}>|null  $lines
     */
    public function refund(PosSession $session, Order $sale, string $reason, User $actor, ?array $lines = null): Order
    {
        if (! $session->isOpen()) {
            throw new TillRefused('Open a till before refunding — the money has to come out of a drawer.');
        }

        if ($sale->pos_session_id === null) {
            throw new TillRefused('That sale was not taken at a till, so it cannot be refunded at one.');
        }

        if ($sale->pos_session_id !== $session->getKey() && ! $actor->can('pos.manage')) {
            throw new TillRefused(
                'That sale was rung up on a different till session. A supervisor has to refund it, '.
                'so a cashier cannot quietly reverse yesterday\'s takings.'
            );
        }

        if ($sale->exception_status === ExceptionStatus::Returned) {
            throw new TillRefused("Sale {$sale->order_number} has already been refunded.");
        }

        if (trim($reason) === '') {
            throw new TillRefused('A refund needs a reason. Somebody will ask about it later.');
        }

        return DB::transaction(function () use ($session, $sale, $reason, $actor, $lines): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($sale->getKey());

            if ($locked->exception_status === ExceptionStatus::Returned) {
                throw new TillRefused('Somebody refunded this sale a moment ago.');
            }

            $wanted = $this->resolveLines($locked, $lines);
            $value = $this->returnStock($locked, $session, $actor, $wanted);

            $this->assertWithinRefundLimit($session, $value, $actor);

            $locked->forceFill([
                'returned_amount' => Money::of((string) $locked->returned_amount, $locked->currency)
                    ->plus($value)
                    ->toDecimal(),
            ])->save();

            $order = $locked->refresh();
            $everythingBack = $this->everythingReturned($order);

            if ($everythingBack) {
                $order = $this->states->transition($order, ExceptionStatus::Returned, $actor, $reason);

                if ($this->states->canTransition($order, PaymentStatus::Refunded)) {
                    $order = $this->states->transition($order, PaymentStatus::Refunded, $actor, $reason);
                }
            }

            $this->returnMoney($order, $actor, $reason, $value);
            $this->adjustCommission($order, $reason, $actor, $value, $everythingBack);

            return $order->refresh();
        });
    }

    public function recordCash(PosSession $session, string $kind, string $amount, string $reason, ?User $actor = null): PosCashMovement
    {
        if (! $session->isOpen()) {
            throw new TillRefused('The till is closed. Nothing can move in or out of it.');
        }

        if (bccomp($amount, '0', 4) !== 1) {
            throw new TillRefused('A till movement must be a positive amount.');
        }

        return PosCashMovement::create([
            'pos_session_id' => $session->getKey(),
            'recorded_by' => $actor?->getKey(),
            'kind' => $kind,
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }

    public function expectedCash(PosSession $session): Money
    {
        $expected = Money::of((string) $session->opening_float);

        $cashTaken = Payment::query()
            ->whereIn('order_id', $session->orders()->select('id'))
            ->where('method', 'cash')
            ->sum('amount');

        $expected = $expected->plus(Money::of((string) ($cashTaken ?: '0')));

        foreach ($session->movements()->get() as $movement) {
            $amount = Money::of((string) $movement->amount);

            $expected = $movement->kind === 'cash_in'
                ? $expected->plus($amount)
                : $expected->minus($amount);
        }

        return $expected;
    }

    public function closeSession(PosSession $session, string $countedCash, User $actor, ?string $note = null): PosSession
    {
        if (! $session->isOpen()) {
            throw new TillRefused('This session is already closed.');
        }

        if (bccomp($countedCash, '0', 4) === -1) {
            throw new TillRefused('A counted drawer cannot be negative.');
        }

        return DB::transaction(function () use ($session, $countedCash, $actor, $note): PosSession {
            $expected = $this->expectedCash($session);
            $counted = Money::of($countedCash);

            $session->forceFill([
                'status' => 'closed',
                'closed_by' => $actor->getKey(),
                'counted_cash' => $counted->toDecimal(),
                'expected_cash' => $expected->toDecimal(),
                'variance' => $counted->minus($expected)->toDecimal(),
                'closing_note' => $note,
                'closed_at' => now(),
            ])->save();

            return $session->refresh();
        });
    }

    /**
     * @param  array<int, array{method: string, amount: string, reference?: ?string}>  $tenders
     */
    private function takePayment(Order $order, array $tenders, Money $due, User $cashier): void
    {
        $remaining = $due;

        foreach ($tenders as $tender) {
            $offered = Money::of($tender['amount'], $order->currency);
            $applied = $offered->greaterThan($remaining) ? $remaining : $offered;

            if ($applied->isZero()) {
                continue;
            }

            $this->orders->recordPayment(
                $order->refresh(),
                $applied->toDecimal(),
                $tender['method'],
                $tender['reference'] ?? null,
                $cashier,
            );

            $remaining = $remaining->minus($applied);
        }
    }

    /**
     * @param  array<string, string>  $wanted
     */
    private function returnStock(Order $order, PosSession $session, User $actor, array $wanted): Money
    {
        $warehouse = $session->register?->warehouse;

        if ($warehouse === null) {
            throw new TillRefused('This register has no warehouse, so nothing can be put back on the shelf.');
        }

        $value = Money::zero($order->currency);

        foreach ($order->items()->get() as $item) {
            $quantity = $wanted[$item->getKey()] ?? null;

            if ($quantity === null || bccomp($quantity, '0', 4) !== 1) {
                continue;
            }

            if ($item->product_variant_id !== null) {
                $stock = $this->inventory->lineFor($item->product_variant_id, $warehouse);

                $this->inventory->receive(
                    $stock,
                    $quantity,
                    StockReason::Returned,
                    $order,
                    $actor,
                    "Refunded at the till on {$order->order_number}.",
                );
            }

            $item->forceFill([
                'quantity_returned' => bcadd((string) $item->quantity_returned, $quantity, 4),
            ])->save();

            $value = $value->plus(
                Money::of((string) $item->unit_price, $order->currency)->times($quantity)
            );
        }

        if ($value->isZero()) {
            throw new TillRefused('Nothing was selected to return.');
        }

        return $value;
    }

    /**
     * @param  array<int, array{order_item_id: string, quantity: string}>|null  $lines
     * @return array<string, string>
     */
    private function resolveLines(Order $order, ?array $lines): array
    {
        $items = $order->items()->get()->keyBy(fn ($item): string => (string) $item->getKey());

        if ($lines === null) {
            return $items
                ->mapWithKeys(fn ($item): array => [
                    (string) $item->getKey() => bcsub((string) $item->quantity, (string) $item->quantity_returned, 4),
                ])
                ->filter(fn (string $quantity): bool => bccomp($quantity, '0', 4) === 1)
                ->all();
        }

        $wanted = [];

        foreach ($lines as $line) {
            $item = $items->get($line['order_item_id']);

            if ($item === null) {
                throw new TillRefused('A line being returned does not belong to this sale.');
            }

            $outstanding = bcsub((string) $item->quantity, (string) $item->quantity_returned, 4);

            if (bccomp($line['quantity'], $outstanding, 4) === 1) {
                throw new TillRefused(
                    "{$item->sku}: {$line['quantity']} was offered back but only {$outstanding} of it is still with the customer."
                );
            }

            $wanted[(string) $item->getKey()] = $line['quantity'];
        }

        return $wanted;
    }

    private function assertWithinRefundLimit(PosSession $session, Money $value, User $actor): void
    {
        $limit = $session->register?->refund_limit;

        if ($limit === null || $actor->can('pos.manage')) {
            return;
        }

        $ceiling = Money::of((string) $limit);

        if ($value->greaterThan($ceiling)) {
            throw new TillRefused(
                "This refund comes to {$value->format()} and this register lets a cashier refund up to ".
                "{$ceiling->format()}. A supervisor has to take it."
            );
        }
    }

    private function everythingReturned(Order $order): bool
    {
        foreach ($order->items()->get() as $item) {
            if (bccomp((string) $item->quantity_returned, (string) $item->quantity, 4) === -1) {
                return false;
            }
        }

        return true;
    }

    private function returnMoney(Order $order, User $actor, string $reason, Money $value): void
    {
        $tenders = Payment::query()
            ->where('order_id', $order->getKey())
            ->where('amount', '>', 0)
            ->orderByDesc('amount')
            ->get();

        $taken = Money::zero($order->currency);

        foreach ($tenders as $payment) {
            $taken = $taken->plus(Money::of((string) $payment->amount, $order->currency));
        }

        if ($taken->isZero()) {
            return;
        }

        $remaining = $value;
        $last = $tenders->count() - 1;

        foreach ($tenders as $index => $payment) {
            $share = $index === $last
                ? $remaining
                : Money::of(bcdiv(
                    bcmul($value->toDecimal(), (string) $payment->amount, 8),
                    $taken->toDecimal(),
                    4
                ), $order->currency);

            if ($share->isZero()) {
                continue;
            }

            $this->orders->recordPayment(
                $order->refresh(),
                $share->negated()->toDecimal(),
                (string) $payment->method,
                "Refund of {$payment->getKey()} — {$reason}",
                $actor,
            );

            $remaining = $remaining->minus($share);
        }
    }

    private function adjustCommission(Order $order, string $reason, User $actor, Money $value, bool $everythingBack): void
    {
        $accrued = Commission::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', ['reversed'])
            ->where('type', '!=', 'reversal')
            ->where('type', '!=', 'adjustment')
            ->get();

        $sold = Money::of((string) $order->total, $order->currency);

        foreach ($accrued as $commission) {
            if ($everythingBack) {
                $this->commissions->reverse($commission, "Sale refunded at the till: {$reason}", $actor);

                continue;
            }

            if ($sold->isZero()) {
                continue;
            }

            $share = Money::of(bcdiv(
                bcmul((string) $commission->amount, $value->toDecimal(), 8),
                $sold->toDecimal(),
                4
            ), (string) $commission->currency);

            if ($share->isZero()) {
                continue;
            }

            Commission::create([
                'order_id' => $order->getKey(),
                'recipient_user_id' => $commission->recipient_user_id,
                'recipient_role' => $commission->recipient_role,
                'commission_plan_id' => $commission->commission_plan_id,
                'commission_rule_id' => $commission->commission_rule_id,
                'commission_rule_version_id' => $commission->commission_rule_version_id,
                'reverses_commission_id' => $commission->getKey(),
                'type' => 'adjustment',
                'status' => 'approved',
                'is_provisional' => false,
                'period' => now()->format('Y-m'),
                'currency' => $commission->currency,
                'basis_amount' => $value->toDecimal(),
                'rate_type' => $commission->rate_type,
                'rate_applied' => (string) $commission->rate_applied,
                'amount' => $share->negated()->toDecimal(),
                'calc_inputs' => [
                    'part_return_of' => $commission->getKey(),
                    'returned_value' => $value->toDecimal(),
                    'sale_total' => $sold->toDecimal(),
                    'reason' => $reason,
                ],
            ]);
        }
    }

    private function walkToCompleted(Order $order, User $cashier): void
    {
        foreach (self::COUNTER_PATH as $target) {
            if ($order->fulfilment_status === $target) {
                continue;
            }

            $order = $this->states->transition($order, $target, $cashier, 'Counter sale — goods handed over immediately.');
        }
    }
}
