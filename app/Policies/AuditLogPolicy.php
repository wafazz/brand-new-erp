<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'audit';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->allowsRecord($user, 'view', $log);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, 'export');
    }
}
