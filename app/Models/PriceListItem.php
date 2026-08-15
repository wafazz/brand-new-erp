<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['min_quantity' => '1'];

    protected $fillable = ['price_list_id', 'product_variant_id', 'price', 'min_quantity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'min_quantity' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
