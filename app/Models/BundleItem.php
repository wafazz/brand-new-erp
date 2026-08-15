<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleItem extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['quantity' => '1'];

    protected $fillable = ['product_bundle_id', 'product_variant_id', 'quantity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<ProductBundle, $this> */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'product_bundle_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
