<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ?CarbonImmutable $captured_at
 * @property ?CarbonImmutable $converted_at
 */
class Lead extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'new', 'estimated_value' => '0'];

    protected $fillable = ['branch_id', 'assigned_to', 'pipeline_stage_id', 'converted_customer_id', 'converted_order_id', 'reference', 'name', 'phone', 'email', 'status', 'estimated_value', 'captured_at', 'converted_at', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:4',
            'captured_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<LeadActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public static function ownerColumn(): ?string
    {
        return 'assigned_to';
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
