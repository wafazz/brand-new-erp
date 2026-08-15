<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'customers';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->allowsRecord($user, 'view', $customer);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->allowsRecord($user, 'update', $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->allowsRecord($user, 'delete', $customer);
    }
}
