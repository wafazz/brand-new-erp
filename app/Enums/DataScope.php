<?php

declare(strict_types=1);

namespace App\Enums;

enum DataScope: string
{
    case Own = 'own';
    case Team = 'team';
    case Branch = 'branch';
    case Company = 'company';
    case All = 'all';

    public function rank(): int
    {
        return match ($this) {
            self::Own => 0,
            self::Team => 1,
            self::Branch => 2,
            self::Company => 3,
            self::All => 4,
        };
    }

    public function covers(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own records only',
            self::Team => 'Own team',
            self::Branch => 'Own branch',
            self::Company => 'Entire company',
            self::All => 'All companies',
        };
    }

    public function isGrantableToCompanyRole(): bool
    {
        return $this !== self::All;
    }

    public static function widest(self ...$scopes): ?self
    {
        $widest = null;

        foreach ($scopes as $scope) {
            if ($widest === null || $scope->rank() > $widest->rank()) {
                $widest = $scope;
            }
        }

        return $widest;
    }
}
