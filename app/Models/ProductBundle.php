<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBundle extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['pricing_mode' => 'fixed'];

    protected $fillable = ['product_id', 'pricing_mode', 'fixed_price'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fixed_price' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<BundleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }
}
