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

class PurchaseOrder extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'currency' => 'MYR', 'subtotal' => '0', 'tax_amount' => '0', 'total' => '0'];

    protected $fillable = ['branch_id', 'warehouse_id', 'supplier_id', 'purchase_request_id', 'created_by', 'reference', 'status', 'currency', 'expected_at', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_at' => 'immutable_datetime',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return HasMany<SupplierBill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public static function ownerColumn(): ?string
    {
        return null;
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
