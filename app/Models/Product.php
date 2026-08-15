<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany;
    use HasUlid;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'product', 'status' => 'active', 'has_variants' => false, 'is_stock_tracked' => true];

    protected $fillable = ['category_id', 'brand_id', 'unit_of_measure_id', 'tax_rate_id', 'sku', 'name', 'type', 'description', 'has_variants', 'is_stock_tracked', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'has_variants' => 'boolean',
            'is_stock_tracked' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
}
