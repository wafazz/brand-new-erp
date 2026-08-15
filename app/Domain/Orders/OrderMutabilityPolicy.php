<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Enums\FulfilmentStatus;
use App\Models\Order;

class OrderMutabilityPolicy
{
    public const GROUPS = ['items', 'address', 'customer', 'money', 'notes'];

    public function reasonLocked(Order $order, string $group): ?string
    {
        if (! in_array($group, self::GROUPS, true)) {
            return "[{$group}] is not an editable field group on an order.";
        }

        if ($order->exception_status->blocksFulfilment() && $group !== 'notes') {
            return "This order is {$order->exception_status->label()}. Only notes can be changed.";
        }

        return match ($group) {
            'items' => $this->lockedByPicking($order) ?? $this->lockedByPayment($order),
            'money' => $this->lockedByPayment($order),
            'address' => $order->fulfilment_status->hasLeftWarehouse()
                ? 'This order has already shipped, so the delivery address can no longer be changed.'
                : null,
            'customer' => $order->fulfilment_status === FulfilmentStatus::Draft
                ? null
                : 'The customer can only be changed while the order is a draft.',
            'notes' => null,
        };
    }

    public function canEdit(Order $order, string $group): bool
    {
        return $this->reasonLocked($order, $group) === null;
    }

    /** @return array<string, string|null> */
    public function map(Order $order): array
    {
        $map = [];

        foreach (self::GROUPS as $group) {
            $map[$group] = $this->reasonLocked($order, $group);
        }

        return $map;
    }

    private function lockedByPicking(Order $order): ?string
    {
        if (in_array($order->fulfilment_status, [FulfilmentStatus::Picked, FulfilmentStatus::Packed], true)) {
            return 'This order has been picked. Return it to allocated before changing the lines.';
        }

        if ($order->fulfilment_status->hasLeftWarehouse()) {
            return 'This order has already shipped, so its lines can no longer be changed.';
        }

        return null;
    }

    private function lockedByPayment(Order $order): ?string
    {
        if ($order->payment_status->isSettled()) {
            return 'This order is fully paid. Issue a credit note rather than changing the money on it.';
        }

        return null;
    }
}
