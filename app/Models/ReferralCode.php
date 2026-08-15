<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralCode extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    protected $fillable = ['marketer_id', 'campaign_id', 'owner_user_id', 'code', 'is_active', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Marketer, $this> */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
