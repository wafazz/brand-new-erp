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

/**
 * @property array<string, mixed> $calc_inputs
 * @property string $status
 * @property bool $is_provisional
 * @property string $currency
 * @property string $rate_type
 * @property string $type
 */
class Commission extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'direct', 'status' => 'pending', 'is_provisional' => true, 'currency' => 'MYR'];

    protected $fillable = ['order_id', 'order_item_id', 'recipient_user_id', 'recipient_role', 'commission_plan_id', 'commission_rule_id', 'commission_rule_version_id', 'commission_payout_id', 'reverses_commission_id', 'type', 'status', 'is_provisional', 'period', 'currency', 'basis_amount', 'rate_type', 'rate_applied', 'amount', 'calc_inputs', 'approved_by', 'approved_at', 'finalised_at', 'paid_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_provisional' => 'boolean',
            'basis_amount' => 'decimal:4',
            'rate_applied' => 'decimal:4',
            'amount' => 'decimal:4',
            'calc_inputs' => 'array',
            'approved_at' => 'immutable_datetime',
            'finalised_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** @return BelongsTo<CommissionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    /** @return BelongsTo<CommissionRuleVersion, $this> */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(CommissionRuleVersion::class, 'commission_rule_version_id');
    }

    /** @return BelongsTo<Commission, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(Commission::class, 'reverses_commission_id');
    }

    /** @return HasMany<CommissionSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(CommissionSource::class);
    }

    /** @return HasMany<CommissionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CommissionEvent::class);
    }

    public static function ownerColumn(): ?string
    {
        return 'recipient_user_id';
    }

    public static function branchColumn(): ?string
    {
        return null;
    }
}
