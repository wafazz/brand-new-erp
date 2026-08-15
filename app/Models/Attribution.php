<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ?CarbonImmutable $captured_at
 */
class Attribution extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['touch_type' => 'first'];

    protected $fillable = ['attributable_type', 'attributable_id', 'touch_type', 'channel_id', 'campaign_id', 'marketer_id', 'referral_code_id', 'promotion_rule_id', 'lead_id', 'salesperson_user_id', 'sales_team_id', 'branch_id', 'source', 'medium', 'raw', 'captured_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'captured_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Marketer, $this> */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_user_id');
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function salesTeam(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }
}
