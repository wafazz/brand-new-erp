<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Marketer extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    protected $fillable = ['user_id', 'marketing_team_id', 'code', 'status', 'joined_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MarketingTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(MarketingTeam::class, 'marketing_team_id');
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
