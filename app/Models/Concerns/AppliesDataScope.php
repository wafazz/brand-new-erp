<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Access\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;

trait AppliesDataScope
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user, string $permission): Builder
    {
        return app(ScopeResolver::class)->apply($query, $user, $permission);
    }
}
