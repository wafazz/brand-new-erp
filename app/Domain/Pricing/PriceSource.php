<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

enum PriceSource: string
{
    case CustomerPriceList = 'customer_price_list';
    case GroupPriceList = 'group_price_list';
    case TierPrice = 'tier_price';
    case BranchPriceList = 'branch_price_list';
    case Wholesale = 'wholesale';
    case BaseSellingPrice = 'base_selling_price';

    public function label(): string
    {
        return match ($this) {
            self::CustomerPriceList => 'Customer price list',
            self::GroupPriceList => 'Customer group price list',
            self::TierPrice => 'Quantity tier',
            self::BranchPriceList => 'Branch price list',
            self::Wholesale => 'Wholesale price',
            self::BaseSellingPrice => 'Base selling price',
        };
    }
}
