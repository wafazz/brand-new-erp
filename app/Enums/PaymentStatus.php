<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Unpaid => [self::PartiallyPaid, self::Paid],
            self::PartiallyPaid => [self::Paid, self::Refunded],
            self::Paid => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function isSettled(): bool
    {
        return $this === self::Paid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Unpaid => 'neutral',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Refunded => 'danger',
        };
    }
}
