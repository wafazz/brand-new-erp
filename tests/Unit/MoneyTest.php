<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Support\Money;

it('refuses to be built from a float', function (): void {
    expect(fn () => Money::of(12.50))->toThrow(InvalidArgumentException::class, 'Refusing to build Money from a float');
});

it('refuses a non-numeric string', function (): void {
    expect(fn () => Money::of('twelve'))->toThrow(InvalidArgumentException::class);
});

it('normalises to four decimal places', function (): void {
    expect(Money::of('12.5')->toDecimal())->toBe('12.5000')
        ->and(Money::of(12)->toDecimal())->toBe('12.0000');
});

it('adds and subtracts exactly', function (): void {
    expect(Money::of('0.1')->plus(Money::of('0.2'))->toDecimal())->toBe('0.3000')
        ->and(Money::of('1000.00')->minus(Money::of('520.00'))->toDecimal())->toBe('480.0000');
});

it('computes a percentage without drift', function (): void {
    expect(Money::of('1000.00')->percentage('12')->toDecimal())->toBe('120.0000')
        ->and(Money::of('320.80')->percentage('12')->toDecimal())->toBe('38.4960');
});

it('refuses to combine different currencies', function (): void {
    expect(fn () => Money::of('10', 'MYR')->plus(Money::of('10', 'SGD')))
        ->toThrow(InvalidArgumentException::class, 'Cannot combine MYR with SGD');
});

it('sums an iterable', function (): void {
    $items = [Money::of('10.25'), Money::of('4.75'), Money::of('5.00')];

    expect(Money::sum($items)->toDecimal())->toBe('20.0000');
});

it('represents negative amounts', function (): void {
    expect(Money::of('100')->minus(Money::of('150'))->isNegative())->toBeTrue()
        ->and(Money::of('-50')->negated()->toDecimal())->toBe('50.0000');
});

it('compares amounts', function (): void {
    expect(Money::of('10')->greaterThan(Money::of('5')))->toBeTrue()
        ->and(Money::of('5')->lessThan(Money::of('10')))->toBeTrue()
        ->and(Money::of('5.0000')->equals(Money::of('5')))->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('formats for display', function (): void {
    expect(Money::of('1234.5')->format())->toBe('MYR 1,234.50')
        ->and((string) Money::of('99'))->toBe('MYR 99.00');
});

it('reads decimal casts as strings so money never becomes a float', function (): void {
    $variant = new ProductVariant(['selling_price' => '19.9900', 'cost_price' => '10.0000']);
    $tax = new TaxRate(['rate_percent' => '6.0000']);

    expect($variant->selling_price)->toBeString()
        ->and($variant->cost_price)->toBeString()
        ->and($tax->rate_percent)->toBeString()
        ->and(Money::of($variant->selling_price)->toDecimal())->toBe('19.9900');
});
