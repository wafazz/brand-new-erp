<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['product_id', 'path', 'alt', 'sort', 'is_primary'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
