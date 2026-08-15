<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ?CarbonImmutable $billed_at
 * @property ?CarbonImmutable $due_at
 */
class SupplierBill extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'currency' => 'MYR', 'subtotal' => '0', 'tax_amount' => '0', 'total' => '0', 'paid_amount' => '0'];

    protected $fillable = ['purchase_order_id', 'supplier_id', 'recorded_by', 'reference', 'supplier_invoice_number', 'status', 'currency', 'billed_at', 'due_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billed_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
        ];
    }

    /** @return HasMany<SupplierBillItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierBillItem::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
