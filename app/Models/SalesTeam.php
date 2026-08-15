<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesTeam extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    protected $fillable = ['branch_id', 'territory_id', 'manager_user_id', 'parent_id', 'code', 'name', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<SalesTeamMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SalesTeamMember::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'parent_id');
    }

    public static function ownerColumn(): ?string
    {
        return 'manager_user_id';
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
