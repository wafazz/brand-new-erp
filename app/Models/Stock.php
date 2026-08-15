<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    protected $table = 'stock';

    /** @var array<string, mixed> */
    protected $attributes = ['on_hand' => '0', 'reserved' => '0', 'incoming' => '0'];

    protected $fillable = ['warehouse_id', 'branch_id', 'product_variant_id', 'low_stock_threshold'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:4',
            'reserved' => 'decimal:4',
            'incoming' => 'decimal:4',
            'low_stock_threshold' => 'decimal:4',
        ];
    }

    public static function ownerColumn(): ?string
    {
        return null;
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}
