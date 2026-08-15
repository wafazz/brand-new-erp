<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'branches';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->allowsRecord($user, 'view', $branch);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->allowsRecord($user, 'update', $branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->allowsRecord($user, 'delete', $branch) && ! $branch->is_default;
    }
}
