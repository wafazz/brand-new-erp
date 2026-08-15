<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditRecorder
{
    /** @var array<int, string> */
    private const REDACTED = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'email', 'phone', 'ip_address', 'bank_account', 'tax_no',
    ];

    public function record(
        string $action,
        string $module,
        ?Model $subject = null,
        ?User $actor = null,
        ?string $reason = null,
        ?string $branchId = null,
    ): AuditLog {
        $old = null;
        $new = null;

        if ($subject !== null) {
            $old = $this->redact($subject->getOriginal());
            $new = $this->redact($subject->getChanges() ?: $subject->getAttributes());
        }

        return AuditLog::create([
            'branch_id' => $branchId,
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'module' => $module,
            'auditable_type' => $subject === null ? null : $subject::class,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => Request::hasSession() ? Request::ip() : null,
            'user_agent' => Str::limit((string) Request::userAgent(), 250, ''),
            'reason' => $reason,
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = in_array($key, self::REDACTED, true) ? '[redacted]' : $value;
        }

        return $redacted;
    }
}
