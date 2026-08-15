<?php

declare(strict_types=1);

use App\Enums\DataScope;

it('ranks scopes from narrowest to widest', function (): void {
    expect(DataScope::Own->rank())->toBeLessThan(DataScope::Team->rank())
        ->and(DataScope::Team->rank())->toBeLessThan(DataScope::Branch->rank())
        ->and(DataScope::Branch->rank())->toBeLessThan(DataScope::Company->rank())
        ->and(DataScope::Company->rank())->toBeLessThan(DataScope::All->rank());
});

it('knows which scopes it covers', function (): void {
    expect(DataScope::Branch->covers(DataScope::Own))->toBeTrue()
        ->and(DataScope::Own->covers(DataScope::Branch))->toBeFalse()
        ->and(DataScope::Own->covers(DataScope::Own))->toBeTrue();
});

it('picks the widest scope a user holds', function (): void {
    expect(DataScope::widest(DataScope::Own, DataScope::Branch, DataScope::Team))
        ->toBe(DataScope::Branch);
});

it('returns null when no scopes are held', function (): void {
    expect(DataScope::widest())->toBeNull();
});

it('never grants the platform-wide scope to a company role', function (): void {
    expect(DataScope::All->isGrantableToCompanyRole())->toBeFalse();

    foreach ([DataScope::Own, DataScope::Team, DataScope::Branch, DataScope::Company] as $scope) {
        expect($scope->isGrantableToCompanyRole())->toBeTrue();
    }
});
