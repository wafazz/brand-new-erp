<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PaymentStatus $payment_status
 * @property FulfilmentStatus $fulfilment_status
 * @property ExceptionStatus $exception_status
 * @property string $currency
 * @property bool $is_cod
 * @property ?CarbonImmutable $placed_at
 * @property bool $costs_reconciled
 */
class Order extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'payment_status' => 'unpaid',
        'fulfilment_status' => 'draft',
        'exception_status' => 'none',
        'is_cod' => false,
        'currency' => 'MYR',
        'subtotal' => '0',
        'discount_amount' => '0',
        'tax_amount' => '0',
        'shipping_amount' => '0',
        'total' => '0',
        'paid_amount' => '0',
        'ship_country' => 'MY',
    ];

    protected $fillable = [
        'branch_id', 'pos_session_id', 'customer_id', 'owner_user_id', 'order_number', 'is_cod',
        'customer_name', 'customer_phone', 'customer_email',
        'ship_line1', 'ship_line2', 'ship_city', 'ship_postcode', 'ship_state', 'ship_country',
        'currency', 'placed_at', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_status' => PaymentStatus::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'exception_status' => ExceptionStatus::class,
            'is_cod' => 'boolean',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'shipping_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'placed_at' => 'immutable_datetime',
        ];
    }

    public static function ownerColumn(): ?string
    {
        return 'owner_user_id';
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function outstanding(): Money
    {
        return Money::of((string) $this->total, $this->currency)
            ->minus(Money::of((string) $this->paid_amount, $this->currency));
    }

    public function isFullyPaid(): bool
    {
        return ! $this->outstanding()->isNegative() && $this->outstanding()->isZero();
    }
}
