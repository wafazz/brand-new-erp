<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTeamMember extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['role_in_team' => 'member', 'is_active' => true];

    protected $fillable = ['sales_team_id', 'user_id', 'role_in_team', 'joined_at', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
