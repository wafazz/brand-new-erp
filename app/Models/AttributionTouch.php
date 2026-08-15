<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property ?CarbonImmutable $occurred_at
 */
class AttributionTouch extends Model
{
    use BelongsToCompany;
    use HasUlid;

    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'sequence', 'channel_id', 'campaign_id', 'marketer_id', 'referral_code_id', 'source', 'medium', 'raw', 'occurred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'raw' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('AttributionTouch rows are append-only.');
        });
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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
}
