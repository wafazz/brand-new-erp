<?php

declare(strict_types=1);

namespace App\Enums;

enum FulfilmentStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Allocated = 'allocated';
    case Picked = 'picked';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Approved, self::Draft],
            self::Approved => [self::Allocated, self::Pending],
            self::Allocated => [self::Picked, self::Approved],
            self::Picked => [self::Packed, self::Allocated],
            self::Packed => [self::Shipped, self::Picked],
            self::Shipped => [self::Delivered],
            self::Delivered => [self::Completed],
            self::Completed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function hasLeftWarehouse(): bool
    {
        return in_array($this, [self::Shipped, self::Delivered, self::Completed], true);
    }

    public function reservesStock(): bool
    {
        return in_array($this, [self::Allocated, self::Picked, self::Packed], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Pending => 'neutral',
            self::Approved, self::Allocated, self::Picked, self::Packed => 'info',
            self::Shipped, self::Delivered => 'warning',
            self::Completed => 'success',
        };
    }
}
