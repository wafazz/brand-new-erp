<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'purchasing';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, PurchaseOrder $record): bool
    {
        return $this->allowsRecord($user, 'view', $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, PurchaseOrder $record): bool
    {
        return $this->allowsRecord($user, 'create', $record);
    }

    public function approve(User $user, PurchaseOrder $record): bool
    {
        return $this->allowsRecord($user, 'approve', $record);
    }
}
