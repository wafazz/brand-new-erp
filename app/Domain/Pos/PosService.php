<?php

declare(strict_types=1);

namespace App\Domain\Pos;

use App\Domain\Numbering\DocumentNumberService;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\FulfilmentStatus;
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
