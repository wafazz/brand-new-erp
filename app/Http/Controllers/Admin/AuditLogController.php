<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $entries = AuditLog::query()
            ->visibleTo($request->user(), 'audit.view')
            ->with(['actor:id,name', 'branch:id,name'])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->getKey(),
                'action' => $log->action,
                'module' => $log->module,
                'actor' => $log->actor?->name,
                'branch' => $log->branch?->name,
                'reason' => $log->reason,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Admin/AuditLogs/Index', ['entries' => $entries]);
    }
}
