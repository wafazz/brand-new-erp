<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ?CarbonImmutable $starts_on
 * @property ?CarbonImmutable $next_invoice_on
 * @property ?CarbonImmutable $ends_on
 * @property ?CarbonImmutable $cancelled_at
 */
class Subscription extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'quantity' => '1', 'currency' => 'MYR', 'collect_online' => false];

    protected $fillable = [
        'customer_id', 'subscription_plan_id', 'owner_user_id', 'reference', 'status',
        'quantity', 'unit_price', 'currency', 'collect_online', 'starts_on', 'next_invoice_on', 'ends_on',
        'cancelled_at', 'cancel_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'collect_online' => 'boolean',
            'starts_on' => 'immutable_date',
            'next_invoice_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function chargeAmount(): Money
    {
        return Money::of((string) $this->unit_price, $this->currency)->times((string) $this->quantity);
    }

    public function isBillable(): bool
    {
        return $this->status === 'active';
    }

    public static function ownerColumn(): ?string
    {
        return 'owner_user_id';
    }

    public static function branchColumn(): ?string
    {
        return null;
    }
}
