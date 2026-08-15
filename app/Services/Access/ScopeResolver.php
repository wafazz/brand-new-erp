<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Contracts\Scopeable;
use App\Enums\DataScope;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ScopeResolver
{
    public function __construct(private readonly CompanyContext $context) {}

    public function for(User $user, string $permission): ?DataScope
    {
        if (! $user->can($permission)) {
            return null;
        }

        $companyId = $this->context->idOrFail(self::class);

        $roleIds = $user->roles()->pluck('id')->all();

        if ($roleIds === []) {
            return null;
        }

        $scopes = RolePermissionScope::query()
            ->where('company_id', $companyId)
            ->whereIn('role_id', $roleIds)
            ->whereHas('permission', fn (Builder $query) => $query->where('name', $permission))
            ->pluck('scope')
            ->map(static fn (DataScope|string $scope): DataScope => $scope instanceof DataScope ? $scope : DataScope::from($scope))
            ->all();

        if ($scopes === []) {
            return null;
        }

        return DataScope::widest(...$scopes);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, User $user, string $permission): Builder
    {
        $model = $query->getModel();

        if (! $model instanceof Scopeable) {
            throw new InvalidArgumentException(
                $model::class.' must implement '.Scopeable::class.' before a data scope can be applied to it.'
            );
        }

        $scope = $this->for($user, $permission);

        if ($scope === null) {
            return $query->whereRaw('1 = 0');
        }

        $companyId = $this->context->idOrFail(self::class);
        $ownerColumn = $model::ownerColumn();
        $branchColumn = $model::branchColumn();

        return match ($scope) {
            DataScope::All, DataScope::Company => $query,
            DataScope::Branch => $branchColumn === null
                ? $query->whereRaw('1 = 0')
                : $this->applyBranch($query, $branchColumn, $user, $companyId),
            DataScope::Team => $ownerColumn === null
                ? $query->whereRaw('1 = 0')
                : $query->whereIn(
                    $query->qualifyColumn($ownerColumn),
                    $user->subordinateUserIdsFor($companyId)->all()
                ),
            DataScope::Own => $ownerColumn === null
                ? $query->whereRaw('1 = 0')
                : $query->where($query->qualifyColumn($ownerColumn), $user->getKey()),
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyBranch(Builder $query, string $column, User $user, string $companyId): Builder
    {
        $branchIds = $user->branchIdsFor($companyId)->all();

        if ($branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->qualifyColumn($column), $branchIds);
    }
}
