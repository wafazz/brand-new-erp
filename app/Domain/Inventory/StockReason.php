<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

enum StockReason: string
{
    case Opening = 'opening';
    case Received = 'received';
    case Sold = 'sold';
    case Returned = 'returned';
    case Adjustment = 'adjustment';
    case StockTake = 'stock_take';
    case Damaged = 'damaged';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
