<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Commission;
use App\Models\User;

class CommissionPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'commissions';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Commission $commission): bool
    {
        return $this->allowsRecord($user, 'view', $commission);
    }

    public function approve(User $user, Commission $commission): bool
    {
        return $this->allowsRecord($user, 'approve', $commission);
    }

    public function pay(User $user, Commission $commission): bool
    {
        return $this->allowsRecord($user, 'pay', $commission);
    }
}
