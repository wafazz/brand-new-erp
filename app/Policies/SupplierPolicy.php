<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'suppliers';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->allowsRecord($user, 'view', $supplier);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->allowsRecord($user, 'update', $supplier);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->allowsRecord($user, 'delete', $supplier);
    }
}
