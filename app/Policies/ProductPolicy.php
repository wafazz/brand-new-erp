<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'products';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->allowsRecord($user, 'view', $product);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->allowsRecord($user, 'update', $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->allowsRecord($user, 'delete', $product);
    }
}
