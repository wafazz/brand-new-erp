<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermissionScope extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['role_id', 'permission_id', 'scope'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scope' => DataScope::class];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Permission, $this> */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
