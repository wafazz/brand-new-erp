<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptCost;
use App\Models\GoodsReceiptItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CostingService
{
    public function __construct(private readonly LandedCostAllocator $allocator) {}

    public function addLandedCost(
        GoodsReceipt $receipt,
        string $kind,
        string $amount,
        string $allocation = 'by_value',
        ?User $actor = null,
        ?string $note = null,
    ): GoodsReceiptCost {
        return DB::transaction(function () use ($receipt, $kind, $amount, $allocation, $actor, $note): GoodsReceiptCost {
            $cost = GoodsReceiptCost::create([
                'goods_receipt_id' => $receipt->getKey(),
                'recorded_by' => $actor?->getKey(),
                'kind' => $kind,
                'allocation' => $allocation,
                'amount' => $amount,
                'note' => $note,
            ]);

            $this->applyCosting($receipt);

            return $cost->refresh();
        });
    }

    public function applyCosting(GoodsReceipt $receipt): int
    {
        return DB::transaction(function () use ($receipt): int {
            $this->allocator->allocate($receipt);

            $variantIds = GoodsReceiptItem::query()
                ->where('goods_receipt_id', $receipt->getKey())
                ->pluck('product_variant_id')
                ->unique();

            foreach ($variantIds as $variantId) {
                $this->recomputeAverage((string) $variantId);
            }

            return $variantIds->count();
        });
    }

    public function recomputeAverage(string $variantId): ?Money
    {
        $rows = GoodsReceiptItem::query()
            ->where('product_variant_id', $variantId)
            ->get(['quantity', 'unit_cost', 'landed_unit_cost']);

        if ($rows->isEmpty()) {
            return null;
        }

        $value = Money::zero();
        $quantity = '0';

        foreach ($rows as $row) {
            $unit = $row->landed_unit_cost ?? $row->unit_cost;
            $value = $value->plus(Money::of((string) $unit)->times((string) $row->quantity));
            $quantity = bcadd($quantity, (string) $row->quantity, 4);
        }

        if (bccomp($quantity, '0', 4) === 0) {
            return null;
        }

        $average = Money::of(bcdiv($value->toDecimal(), $quantity, 4));

        ProductVariant::query()->whereKey($variantId)->first()?->forceFill([
            'average_cost' => $average->toDecimal(),
            'cost_quantity' => $quantity,
        ])->save();

        return $average;
    }

    public function costFor(ProductVariant $variant): Money
    {
        return $variant->average_cost === null
            ? Money::of((string) $variant->cost_price)
            : Money::of((string) $variant->average_cost);
    }

    public function costSourceFor(ProductVariant $variant): string
    {
        return $variant->average_cost === null ? 'standard' : 'average';
    }
}
