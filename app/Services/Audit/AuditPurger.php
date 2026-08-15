<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AuditPurger
{
    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function purge(string $reason, Closure $callback): mixed
    {
        if (trim($reason) === '') {
            throw new RuntimeException('An audit purge requires a stated reason. Erasure without a record is not erasure.');
        }

        Log::warning('Audit purge authorised.', ['reason' => $reason]);

        return DB::transaction(function () use ($callback): mixed {
            DB::statement("SET LOCAL app.audit_purge = 'on'");

            return $callback();
        });
    }
}
