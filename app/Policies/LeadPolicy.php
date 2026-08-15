<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy extends BasePolicy
{
    protected function group(): string
    {
        return 'leads';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->allowsRecord($user, 'view', $lead);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->allowsRecord($user, 'update', $lead);
    }

    public function convert(User $user, Lead $lead): bool
    {
        return $this->allowsRecord($user, 'convert', $lead);
    }
}
