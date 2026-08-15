<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use BelongsToCompany;
    use HasUlid;

    /** @return HasMany<RolePermissionScope, $this> */
    public function permissionScopes(): HasMany
    {
        return $this->hasMany(RolePermissionScope::class);
    }
}
