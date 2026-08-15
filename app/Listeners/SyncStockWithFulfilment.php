<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Support\Money;

class SyncStockWithFulfilment
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(OrderStatusChanged $event): void
    {
        match (true) {
            $event->to === FulfilmentStatus::Allocated => $this->reserve($event->order),
            $event->to === FulfilmentStatus::Shipped => $this->commit($event->order, $event),
            $event->to === ExceptionStatus::Cancelled => $this->release($event->order),
            $event->to === ExceptionStatus::Returned => $this->takeBack($event->order, $event),
            default => null,
        };
    }

    private function reserve(Order $order): void
    {
        $warehouse = $this->warehouseFor($order);

        if ($warehouse === null) {
            return;
        }

        foreach ($order->items()->get() as $item) {
            if ($item->product_variant_id === null) {
                continue;
            }

            $stock = $this->inventory->lineFor($item->product_variant_id, $warehouse);

            $this->inventory->reserve($stock, (string) $item->quantity, $order, $item);

            $item->forceFill(['quantity_allocated' => (string) $item->quantity])->save();
        }
    }

    private function commit(Order $order, OrderStatusChanged $event): void
    {
        $reservations = StockReservation::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'held')
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            $this->inventory->commit($reservation, $event->actor);
        }

        foreach ($order->items()->get() as $item) {
            $item->forceFill(['quantity_shipped' => (string) $item->quantity])->save();
        }
    }

    private function release(Order $order): void
    {
        $reservations = StockReservation::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'held')
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            $this->inventory->release($reservation);
        }

        foreach ($order->items()->get() as $item) {
            $item->forceFill(['quantity_allocated' => '0'])->save();
        }
    }

    private function takeBack(Order $order, OrderStatusChanged $event): void
    {
        $warehouse = $this->warehouseFor($order);

        if ($warehouse === null) {
            return;
        }

        $returned = Money::of((string) $order->returned_amount, $order->currency);

        foreach ($order->items()->get() as $item) {
            $outstanding = bcsub((string) $item->quantity, (string) $item->quantity_returned, 4);

            if (bccomp($outstanding, '0', 4) !== 1) {
                continue;
            }

            if ($item->product_variant_id !== null) {
                $stock = $this->inventory->lineFor($item->product_variant_id, $warehouse);

                $this->inventory->receive(
                    $stock,
                    $outstanding,
                    StockReason::Returned,
                    $order,
                    $event->actor,
                    "Returned on {$order->order_number}.",
                );
            }

            $item->forceFill(['quantity_returned' => (string) $item->quantity])->save();

            $returned = $returned->plus(
                Money::of((string) $item->unit_price, $order->currency)->times($outstanding)
            );
        }

        $order->forceFill(['returned_amount' => $returned->toDecimal()])->save();
    }

    private function warehouseFor(Order $order): ?Warehouse
    {
        if ($order->pos_session_id !== null) {
            $register = PosSession::query()->whereKey($order->pos_session_id)->first()?->register;

            if ($register?->warehouse !== null) {
                return $register->warehouse;
            }
        }

        return Warehouse::query()
            ->where('is_active', true)
            ->when($order->branch_id !== null, fn ($q) => $q->where('branch_id', $order->branch_id))
            ->orderByDesc('is_default')
            ->first() ?? Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->first();
    }
}
