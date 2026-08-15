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

/**
 * @property ?CarbonImmutable $starts_on
 * @property ?CarbonImmutable $ends_on
 * @property ?CarbonImmutable $decided_at
 */
class LeaveRequest extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'leave_type_id', 'user_id', 'decided_by', 'reference', 'status',
        'starts_on', 'ends_on', 'days', 'reason', 'decision_note', 'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public static function ownerColumn(): ?string
    {
        return 'user_id';
    }

    public static function branchColumn(): ?string
    {
        return null;
    }
}
