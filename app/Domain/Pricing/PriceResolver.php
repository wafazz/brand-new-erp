<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\PromotionRule;
use App\Models\TierPrice;
use App\Support\Money;
use Illuminate\Support\Carbon;

class PriceResolver
{
    public function resolve(
        ProductVariant $variant,
        ?Customer $customer = null,
        string|int $quantity = 1,
        ?string $branchId = null,
        bool $wholesale = false,
    ): PriceQuote {
        $currency = $customer !== null ? $customer->currency : Money::DEFAULT_CURRENCY;
        $trail = [];

        $resolved = $this->firstMatch($variant, $customer, $quantity, $branchId, $wholesale, $currency, $trail);

        [$discount, $promotions] = $this->discountFor($variant, $resolved['amount'], $quantity, $currency);

        $unit = $resolved['amount']->minus($discount);

        if ($unit->isNegative()) {
            $discount = $resolved['amount'];
            $unit = Money::zero($currency);
        }

        return new PriceQuote(
            unitPrice: $unit,
            base: $resolved['amount'],
            discount: $discount,
            source: $resolved['source'],
            sourceId: $resolved['reference'],
            trail: $trail,
            promotions: $promotions,
        );
    }

    /**
     * @param  array<int, PriceStep>  $trail
     * @return array{amount: Money, source: PriceSource, reference: ?string}
     */
    private function firstMatch(
        ProductVariant $variant,
        ?Customer $customer,
        string|int $quantity,
        ?string $branchId,
        bool $wholesale,
        string $currency,
        array &$trail,
    ): array {
        if ($customer?->price_list_id !== null) {
            $item = $this->priceListItem($customer->price_list_id, $variant, $quantity);

            if ($item !== null) {
                $trail[] = new PriceStep(PriceSource::CustomerPriceList, true, 'Customer has a dedicated price list with a matching break.', $item->getKey(), (string) $item->price);

                return ['amount' => Money::of((string) $item->price, $currency), 'source' => PriceSource::CustomerPriceList, 'reference' => $item->getKey()];
            }

            $trail[] = new PriceStep(PriceSource::CustomerPriceList, false, 'Customer price list holds no break for this variant at this quantity.');
        } else {
            $trail[] = new PriceStep(PriceSource::CustomerPriceList, false, 'Customer has no dedicated price list.');
        }

        $groupListId = $customer?->group?->price_list_id;

        if ($groupListId !== null) {
            $item = $this->priceListItem($groupListId, $variant, $quantity);

            if ($item !== null) {
                $trail[] = new PriceStep(PriceSource::GroupPriceList, true, 'Customer group price list matched.', $item->getKey(), (string) $item->price);

                return ['amount' => Money::of((string) $item->price, $currency), 'source' => PriceSource::GroupPriceList, 'reference' => $item->getKey()];
            }

            $trail[] = new PriceStep(PriceSource::GroupPriceList, false, 'Group price list holds no break for this variant at this quantity.');
        } else {
            $trail[] = new PriceStep(PriceSource::GroupPriceList, false, 'Customer belongs to no group with a price list.');
        }

        $tier = TierPrice::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($customer): void {
                $query->whereNull('customer_group_id');

                if ($customer?->customer_group_id !== null) {
                    $query->orWhere('customer_group_id', $customer->customer_group_id);
                }
            })
            ->orderByDesc('min_quantity')
            ->first();

        if ($tier !== null) {
            $trail[] = new PriceStep(PriceSource::TierPrice, true, "Quantity {$quantity} clears the {$tier->min_quantity} tier.", $tier->getKey(), (string) $tier->price);

            return ['amount' => Money::of((string) $tier->price, $currency), 'source' => PriceSource::TierPrice, 'reference' => $tier->getKey()];
        }

        $trail[] = new PriceStep(PriceSource::TierPrice, false, 'No quantity tier is cleared at this quantity.');

        if ($branchId !== null) {
            $branchList = PriceList::query()
                ->where('branch_id', $branchId)
                ->where('type', 'branch')
                ->where('is_active', true)
                ->first();

            $item = $branchList === null ? null : $this->priceListItem($branchList->getKey(), $variant, $quantity);

            if ($item !== null) {
                $trail[] = new PriceStep(PriceSource::BranchPriceList, true, 'Branch price list matched.', $item->getKey(), (string) $item->price);

                return ['amount' => Money::of((string) $item->price, $currency), 'source' => PriceSource::BranchPriceList, 'reference' => $item->getKey()];
            }

            $trail[] = new PriceStep(PriceSource::BranchPriceList, false, 'Branch has no price list entry for this variant.');
        } else {
            $trail[] = new PriceStep(PriceSource::BranchPriceList, false, 'No branch supplied.');
        }

        if ($wholesale && $variant->wholesale_price !== null) {
            $trail[] = new PriceStep(PriceSource::Wholesale, true, 'Wholesale pricing requested and set on the variant.', $variant->getKey(), (string) $variant->wholesale_price);

            return ['amount' => Money::of((string) $variant->wholesale_price, $currency), 'source' => PriceSource::Wholesale, 'reference' => $variant->getKey()];
        }

        $trail[] = new PriceStep(PriceSource::Wholesale, false, $wholesale ? 'Wholesale requested but not set on the variant.' : 'Wholesale not requested.');

        $trail[] = new PriceStep(PriceSource::BaseSellingPrice, true, 'Fell through to the variant selling price.', $variant->getKey(), (string) $variant->selling_price);

        return ['amount' => Money::of((string) $variant->selling_price, $currency), 'source' => PriceSource::BaseSellingPrice, 'reference' => $variant->getKey()];
    }

    private function priceListItem(string $priceListId, ProductVariant $variant, string|int $quantity): ?PriceListItem
    {
        $list = PriceList::query()->whereKey($priceListId)->where('is_active', true)->first();

        if ($list === null || ! $this->withinWindow($list->valid_from, $list->valid_to)) {
            return null;
        }

        return PriceListItem::query()
            ->where('price_list_id', $priceListId)
            ->where('product_variant_id', $variant->getKey())
            ->where('min_quantity', '<=', $quantity)
            ->orderByDesc('min_quantity')
            ->first();
    }

    /** @return array{0: Money, 1: array<int, array<string, mixed>>} */
    private function discountFor(ProductVariant $variant, Money $base, string|int $quantity, string $currency): array
    {
        $rules = PromotionRule::query()
            ->where('is_active', true)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($variant): void {
                $query->where('applies_to', 'all')
                    ->orWhere(fn ($q) => $q->where('applies_to', 'variant')->where('target_id', $variant->getKey()))
                    ->orWhere(fn ($q) => $q->where('applies_to', 'product')->where('target_id', $variant->product_id));
            })
            ->orderByDesc('priority')
            ->get()
            ->filter(fn (PromotionRule $rule): bool => $this->withinWindow($rule->valid_from, $rule->valid_to));

        $winner = $rules->first();

        if ($winner === null) {
            return [Money::zero($currency), []];
        }

        $discount = $winner->discount_type === 'percent'
            ? $base->percentage((string) $winner->discount_value)
            : Money::of((string) $winner->discount_value, $currency);

        return [$discount, [[
            'id' => $winner->getKey(),
            'code' => $winner->code,
            'name' => $winner->name,
            'type' => $winner->discount_type,
            'value' => $winner->discount_value,
            'amount' => $discount->toDecimal(),
        ]]];
    }

    private function withinWindow(mixed $from, mixed $to): bool
    {
        $now = now();

        if ($from !== null && $now->lt(Carbon::parse((string) $from))) {
            return false;
        }

        return ! ($to !== null && $now->gt(Carbon::parse((string) $to)));
    }
}
