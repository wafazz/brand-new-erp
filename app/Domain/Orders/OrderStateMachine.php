<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderStateMachine
{
    public function __construct(private readonly OrderEventRecorder $events) {}

    public function reasonAgainst(Order $order, BackedEnum $target): ?string
    {
        $current = $this->currentFor($order, $target);

        if ($current === $target) {
            return "This order is already {$this->describe($target)}.";
        }

        if (! in_array($target, $current->allowedNext(), true)) {
            return "An order that is {$this->describe($current)} cannot become {$this->describe($target)}.";
        }

        return $this->crossAxisReason($order, $target);
    }

    public function canTransition(Order $order, BackedEnum $target): bool
    {
        return $this->reasonAgainst($order, $target) === null;
    }

    public function transition(Order $order, BackedEnum $target, ?User $actor = null, ?string $reason = null): Order
    {
        $blocked = $this->reasonAgainst($order, $target);

        if ($blocked !== null) {
            throw new IllegalOrderTransition($blocked);
        }

        $column = $this->columnFor($target);
        $from = $this->currentFor($order, $target);

        return DB::transaction(function () use ($order, $target, $column, $from, $actor, $reason): Order {
            $locked = Order::query()->lockForUpdate()->find($order->getKey());

            if ($locked === null) {
                throw new IllegalOrderTransition('This order no longer exists.');
            }

            if ($this->currentFor($locked, $target) !== $from) {
                throw new IllegalOrderTransition(
                    'Someone else changed this order while you were working on it. Reload and try again.'
                );
            }

            $locked->forceFill([$column => $target->value])->save();

            $this->events->record(
                $locked,
                $this->eventNameFor($target),
                "{$this->axisLabel($target)} moved from {$this->describe($from)} to {$this->describe($target)}.",
                [$column => $from->value],
                [$column => $target->value],
                $actor,
                $reason,
            );

            $fresh = $locked->refresh();

            OrderStatusChanged::dispatch($fresh, $from, $target, $actor);

            return $fresh;
        });
    }

    /** @return array<int, BackedEnum> */
    public function availableTransitions(Order $order, string $axis): array
    {
        $current = match ($axis) {
            'payment' => $order->payment_status,
            'fulfilment' => $order->fulfilment_status,
            'exception' => $order->exception_status,
            default => throw new InvalidArgumentException("[{$axis}] is not an order status axis."),
        };

        return array_values(array_filter(
            $current->allowedNext(),
            fn (BackedEnum $candidate): bool => $this->canTransition($order, $candidate)
        ));
    }

    private function crossAxisReason(Order $order, BackedEnum $target): ?string
    {
        if ($target instanceof FulfilmentStatus) {
            return $this->fulfilmentReason($order, $target);
        }

        if ($target instanceof PaymentStatus) {
            return $this->paymentReason($order, $target);
        }

        if ($target instanceof ExceptionStatus) {
            return $this->exceptionReason($order, $target);
        }

        return null;
    }

    private function fulfilmentReason(Order $order, FulfilmentStatus $target): ?string
    {
        if ($order->exception_status->blocksFulfilment()) {
            return "This order is {$order->exception_status->label()}. Clear the exception before moving fulfilment on.";
        }

        if ($target === FulfilmentStatus::Approved && $order->items()->count() === 0) {
            return 'An order with no lines cannot be approved.';
        }

        if ($target === FulfilmentStatus::Shipped && ! $order->is_cod && ! $order->payment_status->isSettled()) {
            return 'This order is not COD and is not fully paid, so it cannot ship. Mark it COD or record the payment first.';
        }

        return null;
    }

    private function paymentReason(Order $order, PaymentStatus $target): ?string
    {
        $paid = Money::of((string) $order->paid_amount, $order->currency);
        $total = Money::of((string) $order->total, $order->currency);

        if ($target === PaymentStatus::Paid && $paid->lessThan($total)) {
            return "Only {$paid->format()} of {$total->format()} has been received, so this order is not fully paid.";
        }

        if ($target === PaymentStatus::PartiallyPaid && $paid->isZero()) {
            return 'No payment has been received, so this order cannot be partially paid.';
        }

        if ($target === PaymentStatus::Refunded && $paid->isZero()) {
            return 'Nothing has been received against this order, so there is nothing to refund.';
        }

        return null;
    }

    private function exceptionReason(Order $order, ExceptionStatus $target): ?string
    {
        if ($target === ExceptionStatus::Cancelled && $order->fulfilment_status->hasLeftWarehouse()) {
            return 'This order has already shipped. Record a return instead of cancelling it.';
        }

        if ($target === ExceptionStatus::Returned && ! $order->fulfilment_status->hasLeftWarehouse()) {
            return 'This order has not shipped yet, so it cannot be returned. Cancel it instead.';
        }

        return null;
    }

    private function currentFor(Order $order, BackedEnum $target): PaymentStatus|FulfilmentStatus|ExceptionStatus
    {
        return match (true) {
            $target instanceof PaymentStatus => $order->payment_status,
            $target instanceof FulfilmentStatus => $order->fulfilment_status,
            $target instanceof ExceptionStatus => $order->exception_status,
            default => throw new InvalidArgumentException($target::class.' is not an order status.'),
        };
    }

    private function columnFor(BackedEnum $target): string
    {
        return match (true) {
            $target instanceof PaymentStatus => 'payment_status',
            $target instanceof FulfilmentStatus => 'fulfilment_status',
            $target instanceof ExceptionStatus => 'exception_status',
            default => throw new InvalidArgumentException($target::class.' is not an order status.'),
        };
    }

    private function eventNameFor(BackedEnum $target): string
    {
        return match (true) {
            $target instanceof PaymentStatus => 'payment.status_changed',
            $target instanceof FulfilmentStatus => 'fulfilment.status_changed',
            $target instanceof ExceptionStatus => 'exception.status_changed',
            default => 'order.status_changed',
        };
    }

    private function axisLabel(BackedEnum $target): string
    {
        return match (true) {
            $target instanceof PaymentStatus => 'Payment',
            $target instanceof FulfilmentStatus => 'Fulfilment',
            $target instanceof ExceptionStatus => 'Exception',
            default => 'Status',
        };
    }

    private function describe(BackedEnum $status): string
    {
        return method_exists($status, 'label') ? strtolower($status->label()) : $status->value;
    }
}
