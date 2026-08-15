<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromotionRule;
use App\Models\TierPrice;
use App\Support\CompanyContext;

function catalogue(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        $product = Product::create(['sku' => 'WIDGET', 'name' => 'Widget']);

        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '100.0000',
            'cost_price' => '60.0000',
            'wholesale_price' => '80.0000',
            'is_default' => true,
        ]);

        return ['company' => $company, 'product' => $product, 'variant' => $variant];
    });
}

function quote(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

it('falls through to the base selling price when nothing else matches', function (): void {
    $c = catalogue();

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant']));

    expect($result->source)->toBe(PriceSource::BaseSellingPrice)
        ->and($result->unitPrice->toDecimal())->toBe('100.0000')
        ->and($result->base->toDecimal())->toBe('100.0000')
        ->and($result->discount->isZero())->toBeTrue();
});

it('returns a decomposition naming every step it considered', function (): void {
    $c = catalogue();

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant']));
    $trail = collect($result->trail);

    expect($trail)->toHaveCount(6)
        ->and($trail->where('matched', true))->toHaveCount(1)
        ->and($trail->pluck('source')->all())->toBe([
            PriceSource::CustomerPriceList,
            PriceSource::GroupPriceList,
            PriceSource::TierPrice,
            PriceSource::BranchPriceList,
            PriceSource::Wholesale,
            PriceSource::BaseSellingPrice,
        ]);

    foreach ($result->trail as $step) {
        expect($step->reason)->not->toBe('');
    }
});

it('prefers a customer price list over everything else', function (): void {
    $c = catalogue();

    $customer = quote($c['company'], function () use ($c): Customer {
        $list = PriceList::create(['code' => 'VIP', 'name' => 'VIP', 'type' => 'customer']);
        PriceListItem::create([
            'price_list_id' => $list->getKey(),
            'product_variant_id' => $c['variant']->getKey(),
            'price' => '70.0000',
        ]);
        TierPrice::create(['product_variant_id' => $c['variant']->getKey(), 'min_quantity' => '1', 'price' => '90.0000']);

        return Customer::create(['code' => 'C1', 'name' => 'Big Buyer', 'price_list_id' => $list->getKey()]);
    });

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant'], $customer));

    expect($result->source)->toBe(PriceSource::CustomerPriceList)
        ->and($result->unitPrice->toDecimal())->toBe('70.0000');
});

it('uses the customer group price list when the customer has none', function (): void {
    $c = catalogue();

    $customer = quote($c['company'], function () use ($c): Customer {
        $list = PriceList::create(['code' => 'TRADE', 'name' => 'Trade', 'type' => 'group']);
        PriceListItem::create([
            'price_list_id' => $list->getKey(),
            'product_variant_id' => $c['variant']->getKey(),
            'price' => '85.0000',
        ]);
        $group = CustomerGroup::create(['code' => 'TR', 'name' => 'Trade', 'price_list_id' => $list->getKey()]);

        return Customer::create(['code' => 'C2', 'name' => 'Trade Buyer', 'customer_group_id' => $group->getKey()]);
    });

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant'], $customer));

    expect($result->source)->toBe(PriceSource::GroupPriceList)
        ->and($result->unitPrice->toDecimal())->toBe('85.0000');
});

it('picks the highest quantity break the order clears', function (): void {
    $c = catalogue();

    quote($c['company'], function () use ($c): void {
        TierPrice::create(['product_variant_id' => $c['variant']->getKey(), 'min_quantity' => '10', 'price' => '95.0000']);
        TierPrice::create(['product_variant_id' => $c['variant']->getKey(), 'min_quantity' => '50', 'price' => '90.0000']);
        TierPrice::create(['product_variant_id' => $c['variant']->getKey(), 'min_quantity' => '100', 'price' => '85.0000']);
    });

    $resolver = app(PriceResolver::class);

    expect(quote($c['company'], fn () => $resolver->resolve($c['variant'], null, 5))->source)->toBe(PriceSource::BaseSellingPrice)
        ->and(quote($c['company'], fn () => $resolver->resolve($c['variant'], null, 10))->unitPrice->toDecimal())->toBe('95.0000')
        ->and(quote($c['company'], fn () => $resolver->resolve($c['variant'], null, 60))->unitPrice->toDecimal())->toBe('90.0000')
        ->and(quote($c['company'], fn () => $resolver->resolve($c['variant'], null, 100))->unitPrice->toDecimal())->toBe('85.0000');
});

it('uses the wholesale price only when wholesale is requested', function (): void {
    $c = catalogue();
    $resolver = app(PriceResolver::class);

    expect(quote($c['company'], fn () => $resolver->resolve($c['variant'], null, 1, null, false))->unitPrice->toDecimal())->toBe('100.0000')
        ->and(quote($c['company'], fn () => $resolver->resolve($c['variant'], null, 1, null, true))->source)->toBe(PriceSource::Wholesale)
        ->and(quote($c['company'], fn () => $resolver->resolve($c['variant'], null, 1, null, true))->unitPrice->toDecimal())->toBe('80.0000');
});

it('applies a percentage promotion on top of the resolved base', function (): void {
    $c = catalogue();

    quote($c['company'], fn () => PromotionRule::create([
        'code' => 'SAVE10',
        'name' => 'Ten percent off',
        'applies_to' => 'variant',
        'target_id' => $c['variant']->getKey(),
        'discount_type' => 'percent',
        'discount_value' => '10',
    ]));

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant']));

    expect($result->base->toDecimal())->toBe('100.0000')
        ->and($result->discount->toDecimal())->toBe('10.0000')
        ->and($result->unitPrice->toDecimal())->toBe('90.0000')
        ->and($result->promotions)->toHaveCount(1);
});

it('never lets a discount drive the price below zero', function (): void {
    $c = catalogue();

    quote($c['company'], fn () => PromotionRule::create([
        'code' => 'TOOBIG',
        'name' => 'Oversized discount',
        'applies_to' => 'all',
        'discount_type' => 'fixed',
        'discount_value' => '500',
    ]));

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant']));

    expect($result->unitPrice->toDecimal())->toBe('0.0000')
        ->and($result->unitPrice->isNegative())->toBeFalse()
        ->and($result->discount->toDecimal())->toBe('100.0000');
});

it('ignores a promotion outside its validity window', function (): void {
    $c = catalogue();

    quote($c['company'], fn () => PromotionRule::create([
        'code' => 'EXPIRED',
        'name' => 'Last month',
        'applies_to' => 'all',
        'discount_type' => 'percent',
        'discount_value' => '50',
        'valid_from' => now()->subMonths(2),
        'valid_to' => now()->subMonth(),
    ]));

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant']));

    expect($result->discount->isZero())->toBeTrue()
        ->and($result->unitPrice->toDecimal())->toBe('100.0000');
});

it('explains the price in a sentence a salesperson can read', function (): void {
    $c = catalogue();

    quote($c['company'], fn () => PromotionRule::create([
        'code' => 'SAVE10',
        'name' => 'Ten percent off',
        'applies_to' => 'all',
        'discount_type' => 'percent',
        'discount_value' => '10',
    ]));

    $result = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant']));

    expect($result->explain())->toBe('Base selling price MYR 100.00 less MYR 10.00 (Ten percent off) = MYR 90.00');
});

it('serialises the whole decomposition for the wire', function (): void {
    $c = catalogue();

    $payload = quote($c['company'], fn () => app(PriceResolver::class)->resolve($c['variant'])->toArray());

    expect($payload)->toHaveKeys(['unit_price', 'base', 'discount', 'currency', 'source', 'source_id', 'explanation', 'promotions', 'trail'])
        ->and($payload['trail'])->toHaveCount(6)
        ->and($payload['unit_price'])->toBeString();
});
