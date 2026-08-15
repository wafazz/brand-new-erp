<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['stock_adjustment_id', 'product_variant_id', 'quantity_delta', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:4'];
    }
}
