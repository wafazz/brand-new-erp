<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Domain\Numbering\DocumentNumberService;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierBill;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchasingService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberService $numbers,
        private readonly ThreeWayMatch $matcher,
    ) {}

    /** @param array<int, array{purchase_order_item_id: string, quantity: string}> $lines */
    public function receiveGoods(
        PurchaseOrder $order,
        Warehouse $warehouse,
        array $lines,
        ?User $actor = null,
        ?string $supplierDoNumber = null,
    ): GoodsReceipt {
        if ($lines === []) {
            throw new InvalidArgumentException('A goods receipt needs at least one line.');
        }

        return DB::transaction(function () use ($order, $warehouse, $lines, $actor, $supplierDoNumber): GoodsReceipt {
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'received_by' => $actor?->getKey(),
                'reference' => $this->numbers->next('goods_receipt', 'GRN'),
                'supplier_do_number' => $supplierDoNumber,
                'received_at' => now(),
            ]);

            foreach ($lines as $line) {
                $orderItem = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($line['purchase_order_item_id']);

                if ($orderItem->purchase_order_id !== $order->getKey()) {
                    throw new InvalidArgumentException('A receipt line does not belong to this purchase order.');
                }

                $outstanding = bcsub((string) $orderItem->quantity, (string) $orderItem->quantity_received, 4);

                if (bccomp($line['quantity'], $outstanding, 4) === 1) {
                    throw new InvalidArgumentException(
                        "{$orderItem->sku}: {$this->trim($line['quantity'])} received but only ".
                        "{$this->trim($outstanding)} is still outstanding on this order."
                    );
                }

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->getKey(),
                    'purchase_order_item_id' => $orderItem->getKey(),
                    'product_variant_id' => $orderItem->product_variant_id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => (string) $orderItem->unit_cost,
                ]);

                $orderItem->forceFill([
                    'quantity_received' => bcadd((string) $orderItem->quantity_received, $line['quantity'], 4),
                ])->save();

                $stock = $this->inventory->lineFor($orderItem->product_variant_id, $warehouse);

                $this->inventory->receive(
                    $stock,
                    $line['quantity'],
                    StockReason::Received,
                    $receipt,
                    $actor,
                );
            }

            $this->refreshOrderStatus($order->refresh());

            return $receipt->refresh();
        });
    }

    public function assertBillPayable(SupplierBill $bill): void
    {
        $result = $this->matcher->match($bill);

        if (! $result->matched) {
            throw new BillNotPayable((string) $result->reason());
        }
    }

    public function recalculateBill(SupplierBill $bill): SupplierBill
    {
        $subtotal = Money::zero($bill->currency);

        foreach ($bill->items()->get() as $item) {
            $subtotal = $subtotal->plus(Money::of((string) $item->line_total, $bill->currency));
        }

        $bill->forceFill([
            'subtotal' => $subtotal->toDecimal(),
            'total' => $subtotal->plus(Money::of((string) $bill->tax_amount, $bill->currency))->toDecimal(),
        ])->save();

        return $bill->refresh();
    }

    private function refreshOrderStatus(PurchaseOrder $order): void
    {
        $items = $order->items()->get();

        $fullyReceived = $items->every(
            static fn (PurchaseOrderItem $item): bool => bccomp((string) $item->quantity_received, (string) $item->quantity, 4) >= 0
        );

        $anyReceived = $items->contains(
            static fn (PurchaseOrderItem $item): bool => bccomp((string) $item->quantity_received, '0', 4) === 1
        );

        $status = match (true) {
            $fullyReceived => 'received',
            $anyReceived => 'partially_received',
            default => $order->status,
        };

        $order->forceFill(['status' => $status])->save();
    }

    private function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
