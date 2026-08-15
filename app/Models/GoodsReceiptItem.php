<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['landed_unit_cost', 'landed_cost_basis', 'goods_receipt_id', 'purchase_order_item_id', 'product_variant_id', 'quantity', 'unit_cost'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'landed_cost_basis' => 'array',
            'landed_unit_cost' => 'decimal:4',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
