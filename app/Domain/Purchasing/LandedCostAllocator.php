<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptCost;
use App\Models\GoodsReceiptItem;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LandedCostAllocator
{
    public function allocate(GoodsReceipt $receipt): int
    {
        return DB::transaction(function () use ($receipt): int {
            $items = GoodsReceiptItem::query()
                ->where('goods_receipt_id', $receipt->getKey())
                ->with('variant')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw new RuntimeException('A receipt with no lines cannot carry a landed cost.');
            }

            $costs = GoodsReceiptCost::query()
                ->where('goods_receipt_id', $receipt->getKey())
                ->orderBy('id')
                ->get();

            $denominators = $this->denominators($items);

            foreach ($items as $item) {
                $base = Money::of((string) $item->unit_cost);
                $addition = Money::zero();
                $components = [];

                foreach ($costs as $cost) {
                    $share = $this->shareFor($item, $cost, $denominators);
                    $perUnit = bccomp((string) $item->quantity, '0', 4) === 0
                        ? Money::zero()
                        : Money::of(bcdiv($share->toDecimal(), (string) $item->quantity, 4));

                    $addition = $addition->plus($perUnit);

                    $components[] = [
                        'kind' => $cost->kind,
                        'allocation' => $cost->allocation,
                        'pool' => (string) $cost->amount,
                        'share' => $share->toDecimal(),
                        'per_unit' => $perUnit->toDecimal(),
                        'basis' => $this->basisFor($item, $cost, $denominators),
                    ];
                }

                $landed = $base->plus($addition);

                $item->forceFill([
                    'landed_unit_cost' => $landed->toDecimal(),
                    'landed_cost_basis' => [
                        'purchase_unit_cost' => $base->toDecimal(),
                        'landed_unit_cost' => $landed->toDecimal(),
                        'added_per_unit' => $addition->toDecimal(),
                        'components' => $components,
                        'explanation' => $this->explain($base, $addition, $landed, $components),
                    ],
                ])->save();
            }

            return $items->count();
        });
    }

    /**
     * @param  Collection<int, GoodsReceiptItem>  $items
     * @return array<string, string>
     */
    private function denominators($items): array
    {
        $value = Money::zero();
        $quantity = '0';
        $weight = '0';

        foreach ($items as $item) {
            $value = $value->plus(Money::of((string) $item->unit_cost)->times((string) $item->quantity));
            $quantity = bcadd($quantity, (string) $item->quantity, 4);
            $lineWeight = bcmul((string) ($item->variant->weight_grams ?? 0), (string) $item->quantity, 4);
            $weight = bcadd($weight, $lineWeight, 4);
        }

        return [
            'by_value' => $value->toDecimal(),
            'by_quantity' => $quantity,
            'by_weight' => $weight,
        ];
    }

    /** @param array<string, string> $denominators */
    private function shareFor(GoodsReceiptItem $item, GoodsReceiptCost $cost, array $denominators): Money
    {
        $denominator = $denominators[$cost->allocation] ?? '0';

        if (bccomp($denominator, '0', 4) === 0) {
            return Money::zero();
        }

        $numerator = match ($cost->allocation) {
            'by_quantity' => (string) $item->quantity,
            'by_weight' => bcmul((string) ($item->variant->weight_grams ?? 0), (string) $item->quantity, 4),
            default => bcmul((string) $item->unit_cost, (string) $item->quantity, 4),
        };

        $weight = bcdiv($numerator, $denominator, 8);

        return Money::of(bcmul((string) $cost->amount, $weight, 4));
    }

    /** @param array<string, string> $denominators */
    private function basisFor(GoodsReceiptItem $item, GoodsReceiptCost $cost, array $denominators): string
    {
        $denominator = $denominators[$cost->allocation] ?? '0';

        if (bccomp($denominator, '0', 4) === 0) {
            return "No {$cost->allocation} basis exists on this receipt, so nothing was apportioned.";
        }

        $label = match ($cost->allocation) {
            'by_quantity' => 'quantity',
            'by_weight' => 'weight',
            default => 'line value',
        };

        return ucfirst($cost->kind)." apportioned by {$label}.";
    }

    /** @param array<int, array<string, string>> $components */
    private function explain(Money $base, Money $addition, Money $landed, array $components): string
    {
        if ($components === []) {
            return "No landed cost recorded, so the unit cost stays at {$base->format()}.";
        }

        $parts = array_map(
            static fn (array $c): string => "{$c['kind']} {$c['per_unit']}",
            $components
        );

        return "Purchase {$base->format()} plus ".implode(' + ', $parts)." per unit = {$landed->format()}.";
    }
}
