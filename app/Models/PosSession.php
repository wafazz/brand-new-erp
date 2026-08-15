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
 * @property ?CarbonImmutable $opened_at
 * @property ?CarbonImmutable $closed_at
 */
class PosSession extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'opening_float' => '0'];

    protected $fillable = [
        'pos_register_id', 'opened_by', 'closed_by', 'reference', 'status',
        'opening_float', 'counted_cash', 'expected_cash', 'variance', 'closing_note',
        'opened_at', 'closed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:4',
            'counted_cash' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'variance' => 'decimal:4',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PosRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pos_session_id');
    }

    /** @return HasMany<PosCashMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public static function ownerColumn(): ?string
    {
        return 'opened_by';
    }

    public static function branchColumn(): ?string
    {
        return null;
    }
}
