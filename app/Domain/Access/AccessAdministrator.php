<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Models\User;
use App\Services\Access\ScopeResolver;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class AccessAdministrator
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function assertMayAssignRole(User $actor, CompanyRole $target): void
    {
        $missing = array_values(array_filter(
            PermissionRegistry::forRole($target),
            static fn (string $permission): bool => ! $actor->can($permission)
        ));

        if ($missing !== []) {
            $shown = implode(', ', array_slice($missing, 0, 3));
            $more = count($missing) > 3 ? ' and '.(count($missing) - 3).' more' : '';

            throw new AccessChangeRefused(
                "You cannot grant the {$target->value} role, because it carries permissions you do not hold yourself: {$shown}{$more}."
            );
        }
    }

    public function assertMaySetScope(User $actor, string $permission, DataScope $target): void
    {
        if (! $target->isGrantableToCompanyRole()) {
            throw new AccessChangeRefused('The all-companies scope cannot be granted to a company role.');
        }

        $own = $this->scopes->for($actor, $permission);

        if ($own === null) {
            throw new AccessChangeRefused(
                "You cannot set the scope of [{$permission}], because you do not hold that permission yourself."
            );
        }

        if (! $own->covers($target)) {
            throw new AccessChangeRefused(
                "You cannot widen [{$permission}] to \"{$target->label()}\", because your own reach on it is \"{$own->label()}\"."
            );
        }
    }

    public function assertNotSelf(User $actor, CompanyUser $member, string $action): void
    {
        if ($member->user_id === $actor->getKey()) {
            throw new AccessChangeRefused("You cannot {$action} your own access.");
        }
    }

    public function assertOwnerRemains(CompanyUser $member, ?CompanyRole $newRole, ?bool $newActive): void
    {
        if ($member->role !== CompanyRole::Owner) {
            return;
        }

        $stillOwner = ($newRole ?? $member->role) === CompanyRole::Owner
            && ($newActive ?? $member->is_active) === true;

        if ($stillOwner) {
            return;
        }

        $otherOwners = CompanyUser::query()
            ->where('role', CompanyRole::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($member->getKey())
            ->count();

        if ($otherOwners === 0) {
            throw new AccessChangeRefused(
                'This is the last active owner. Promote somebody else to owner first, or the company can never be administered again.'
            );
        }
    }

    /** @param array<string, mixed> $attributes */
    public function addMember(User $actor, User $subject, CompanyRole $role, array $attributes = []): CompanyUser
    {
        $this->assertMayAssignRole($actor, $role);

        return DB::transaction(function () use ($subject, $role, $attributes): CompanyUser {
            $member = CompanyUser::create([
                ...$attributes,
                'user_id' => $subject->getKey(),
                'role' => $role->value,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            $subject->syncRoles([$role->value]);

            $this->registrar->forgetCachedPermissions();

            return $member->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateMember(User $actor, CompanyUser $member, CompanyRole $role, array $attributes = []): CompanyUser
    {
        $this->assertNotSelf($actor, $member, 'change');

        if ($role !== $member->role) {
            $this->assertMayAssignRole($actor, $role);
        }

        $active = array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : null;

        $this->assertOwnerRemains($member, $role, $active);

        return DB::transaction(function () use ($member, $role, $attributes): CompanyUser {
            $member->update([...$attributes, 'role' => $role->value]);

            $member->user?->syncRoles([$role->value]);

            $this->registrar->forgetCachedPermissions();

            return $member->refresh();
        });
    }

    public function setScope(User $actor, Role $role, Permission $permission, DataScope $scope): RolePermissionScope
    {
        $this->assertMaySetScope($actor, $permission->name, $scope);

        if (! $role->hasPermissionTo($permission)) {
            throw new AccessChangeRefused(
                "The {$role->name} role does not carry [{$permission->name}], so it has no scope to set."
            );
        }

        if ($actor->hasRole($role->name)) {
            throw new AccessChangeRefused(
                "You hold the {$role->name} role yourself, so you cannot change what it can reach."
            );
        }

        return DB::transaction(function () use ($role, $permission, $scope): RolePermissionScope {
            $record = RolePermissionScope::query()->updateOrCreate(
                ['role_id' => $role->getKey(), 'permission_id' => $permission->getKey()],
                ['scope' => $scope]
            );

            $this->registrar->forgetCachedPermissions();

            return $record->refresh();
        });
    }
}
