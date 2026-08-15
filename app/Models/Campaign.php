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
 * @property ?CarbonImmutable $starts_at
 * @property ?CarbonImmutable $ends_at
 */
class Campaign extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'budget' => '0'];

    protected $fillable = ['channel_id', 'marketer_id', 'code', 'name', 'status', 'budget', 'starts_at', 'ends_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'budget' => 'decimal:4',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<Marketer, $this> */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    /** @return HasMany<CampaignCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(CampaignCost::class);
    }
}
