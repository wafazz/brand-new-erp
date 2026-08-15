<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CompanyUser;
use App\Models\User;

class CompanyUserPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'users';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, CompanyUser $member): bool
    {
        return $this->allowsRecord($user, 'view', $member);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, CompanyUser $member): bool
    {
        return $this->allowsRecord($user, 'update', $member);
    }

    public function delete(User $user, CompanyUser $member): bool
    {
        return $this->allowsRecord($user, 'delete', $member)
            && $member->user_id !== $user->getKey();
    }
}
