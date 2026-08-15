<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'orders';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->allowsRecord($user, 'view', $order);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Order $order): bool
    {
        return $this->allowsRecord($user, 'update', $order);
    }

    public function approve(User $user, Order $order): bool
    {
        return $this->allowsRecord($user, 'approve', $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->allowsRecord($user, 'cancel', $order);
    }

    public function recordPayment(User $user, Order $order): bool
    {
        if (! $user->can('payments.create')) {
            return false;
        }

        return $this->allowsRecord($user, 'view', $order);
    }

    public function refund(User $user, Order $order): bool
    {
        if (! $user->can('payments.refund')) {
            return false;
        }

        if ($order->pos_session_id !== null && ! $user->can('pos.manage')) {
            return false;
        }

        return $this->allowsRecord($user, 'view', $order);
    }

    public function issueInvoice(User $user, Order $order): bool
    {
        if (! $user->can('invoices.issue')) {
            return false;
        }

        return $this->allowsRecord($user, 'view', $order);
    }
}
