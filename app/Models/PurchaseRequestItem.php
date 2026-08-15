<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestItem extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['purchase_request_id', 'product_variant_id', 'quantity', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }
}
