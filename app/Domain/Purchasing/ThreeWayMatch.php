<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Models\GoodsReceiptItem;
use App\Models\SupplierBill;
use App\Support\Money;

class ThreeWayMatch
{
    public function match(SupplierBill $bill): MatchResult
    {
        $discrepancies = [];

        $bill->loadMissing(['items.purchaseOrderItem', 'purchaseOrder']);

        foreach ($bill->items as $billItem) {
            $orderItem = $billItem->purchaseOrderItem;

            if ($orderItem === null || $orderItem->purchase_order_id !== $bill->purchase_order_id) {
                $discrepancies[] = new MatchDiscrepancy(
                    'unknown',
                    'orphan_line',
                    'A billed line does not belong to this purchase order.'
                );

                continue;
            }

            $received = (string) GoodsReceiptItem::query()
                ->where('purchase_order_item_id', $orderItem->getKey())
                ->sum('quantity');

            if (bccomp((string) $billItem->quantity, $received, 4) === 1) {
                $discrepancies[] = new MatchDiscrepancy(
                    $orderItem->sku,
                    'over_billed_quantity',
                    "{$orderItem->sku}: billed {$this->trim((string) $billItem->quantity)} but only {$this->trim($received)} was received."
                );
            }

            if (bccomp((string) $billItem->unit_cost, (string) $orderItem->unit_cost, 4) !== 0) {
                $ordered = Money::of((string) $orderItem->unit_cost, $bill->currency);
                $billed = Money::of((string) $billItem->unit_cost, $bill->currency);

                $discrepancies[] = new MatchDiscrepancy(
                    $orderItem->sku,
                    'price_variance',
                    "{$orderItem->sku}: ordered at {$ordered->format()} but billed at {$billed->format()}."
                );
            }
        }

        return new MatchResult($discrepancies === [], $discrepancies);
    }

    private function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
