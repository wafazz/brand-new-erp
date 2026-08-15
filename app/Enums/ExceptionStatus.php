<?php

declare(strict_types=1);

namespace App\Enums;

enum ExceptionStatus: string
{
    case None = 'none';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::None => [self::OnHold, self::Cancelled, self::Returned],
            self::OnHold => [self::None, self::Cancelled, self::Returned],
            self::Cancelled => [],
            self::Returned => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function blocksFulfilment(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::OnHold => 'On hold',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::None => 'neutral',
            self::OnHold => 'warning',
            self::Cancelled => 'danger',
            self::Returned => 'danger',
        };
    }
}
