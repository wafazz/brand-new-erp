<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['cost_price' => '0', 'selling_price' => '0', 'is_default' => false, 'is_active' => true];

    protected $fillable = ['average_cost', 'cost_quantity', 'product_id', 'sku', 'name', 'barcode', 'options', 'cost_price', 'selling_price', 'wholesale_price', 'member_price', 'weight_grams', 'is_default', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cost_quantity' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'options' => 'array',
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'wholesale_price' => 'decimal:4',
            'member_price' => 'decimal:4',
            'weight_grams' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
