<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplierBill;
use App\Models\User;

class SupplierBillPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'purchasing';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, SupplierBill $bill): bool
    {
        return $this->allowsRecord($user, 'view', $bill);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function approve(User $user, SupplierBill $bill): bool
    {
        return $this->allowsRecord($user, 'approve', $bill);
    }

    public function pay(User $user, SupplierBill $bill): bool
    {
        if (! $user->can('payments.create')) {
            return false;
        }

        return $this->allowsRecord($user, 'view', $bill);
    }
}
