<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TierPrice extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['min_quantity' => '1'];

    protected $fillable = ['product_variant_id', 'customer_group_id', 'min_quantity', 'price'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:4',
            'price' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
