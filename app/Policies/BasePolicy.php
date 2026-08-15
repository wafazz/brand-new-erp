<?php

declare(strict_types=1);

namespace App\Policies;

use App\Contracts\Scopeable;
use App\Models\User;
use App\Services\Access\ScopeResolver;
use Illuminate\Database\Eloquent\Model;

abstract class BasePolicy
{
    abstract protected function group(): string;

    protected function allows(User $user, string $ability): bool
    {
        return $user->can($this->group().'.'.$ability);
    }

    protected function allowsRecord(User $user, string $ability, Model $record): bool
    {
        $permission = $this->group().'.'.$ability;

        if (! $user->can($permission)) {
            return false;
        }

        if (! $record instanceof Scopeable) {
            return true;
        }

        return app(ScopeResolver::class)
            ->apply($record->newQuery(), $user, $permission)
            ->whereKey($record->getKey())
            ->exists();
    }
}
