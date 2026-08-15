<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['stock_transfer_id', 'product_variant_id', 'quantity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }
}
