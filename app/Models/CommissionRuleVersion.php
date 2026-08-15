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
 * @property ?CarbonImmutable $valid_from
 * @property int $version
 * @property string $rate_type
 */
class CommissionRuleVersion extends Model
{
    use BelongsToCompany;
    use HasUlid;

    public const UPDATED_AT = null;

    protected $fillable = ['commission_rule_id', 'created_by', 'version', 'rate_type', 'rate_value', 'tier_config', 'conditions', 'valid_from', 'valid_to'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rate_value' => 'decimal:4',
            'tier_config' => 'array',
            'conditions' => 'array',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('CommissionRuleVersion rows are immutable.');
        });
    }

    /** @return BelongsTo<CommissionRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }
}
