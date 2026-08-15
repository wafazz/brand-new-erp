<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'invoices';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->allowsRecord($user, 'view', $invoice);
    }

    public function issue(User $user): bool
    {
        return $this->allows($user, 'issue');
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $this->allowsRecord($user, 'void', $invoice);
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        if (! $user->can('payments.create')) {
            return false;
        }

        return $this->allowsRecord($user, 'view', $invoice);
    }
}
