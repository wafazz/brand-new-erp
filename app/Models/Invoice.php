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

class Invoice extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'currency' => 'MYR', 'tax_rate' => '0', 'subtotal' => '0', 'discount_amount' => '0', 'tax_amount' => '0', 'total' => '0', 'paid_amount' => '0'];

    protected $fillable = ['branch_id', 'order_id', 'customer_id', 'issued_by', 'invoice_number', 'status', 'customer_name', 'customer_tax_no', 'bill_line1', 'bill_city', 'bill_postcode', 'bill_state', 'currency', 'tax_label', 'tax_rate', 'issued_at', 'due_at', 'voided_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
