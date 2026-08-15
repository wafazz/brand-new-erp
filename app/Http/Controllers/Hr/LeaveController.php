<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\LeaveRefused;
use App\Domain\Hr\LeaveService;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('leave.view'), 403);

        $user = $request->user();
        $year = (int) now()->format('Y');

        $mine = LeaveRequest::query()
            ->where('user_id', $user->getKey())
            ->with('type:id,name')
            ->orderByDesc('starts_on')
            ->limit(30)
            ->get()
            ->map(fn (LeaveRequest $row): array => $this->present($row))
            ->all();

        return Inertia::render('Hr/Leave', [
            'mine' => $mine,
            'balances' => LeaveType::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (LeaveType $type): array => [
                    'id' => $type->getKey(),
                    'name' => $type->name,
                    'entitlement' => (string) $type->days_per_year,
                    'taken' => $this->leave->takenIn($user, $type, $year),
                    'remaining' => $this->leave->remainingFor($user, $type, $year),
                    'is_paid' => $type->is_paid,
                    'requires_document' => $type->requires_document,
                ])->all(),
            'awaitingMe' => $user->can('leave.approve')
                ? LeaveRequest::query()
                    ->where('status', 'pending')
                    ->with(['type:id,name', 'employee:id,name'])
                    ->orderBy('starts_on')
                    ->get()
                    ->filter(fn (LeaveRequest $row): bool => $this->leave->mayDecideFor($user, $row))
                    ->map(fn (LeaveRequest $row): array => $this->present($row))
                    ->values()
                    ->all()
                : [],
            'year' => (string) $year,
            'can' => [
                'request' => $user->can('leave.request'),
                'approve' => $user->can('leave.approve'),
                'configure' => $user->can('leave.configure'),
            ],
        ]);
    }

    public function types(Request $request): Response
    {
        abort_unless($request->user()->can('leave.configure'), 403);

        return Inertia::render('Hr/LeaveTypes', [
            'types' => LeaveType::query()
                ->orderBy('name')
                ->get()
                ->map(fn (LeaveType $type): array => [
                    'id' => $type->getKey(),
                    'code' => $type->code,
                    'name' => $type->name,
                    'days_per_year' => (string) $type->days_per_year,
                    'is_paid' => $type->is_paid,
                    'requires_document' => $type->requires_document,
                    'is_active' => $type->is_active,
                    'requests' => LeaveRequest::query()->where('leave_type_id', $type->getKey())->count(),
                ])->all(),
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('leave.configure'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('leave_types', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:120'],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['required', 'boolean'],
            'requires_document' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $type = LeaveType::create($data);

        $this->recorder->record('leave_type_created', 'leave', $type, $request->user());

        return back()->with('success', "{$type->name} added.");
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('leave.request'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'leave_type_id' => ['required', 'string', Rule::exists('leave_types', 'id')->where('company_id', $companyId)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        try {
            $leave = $this->leave->request(
                $request->user(),
                $type,
                now()->parse($data['starts_on'])->toImmutable()->startOfDay(),
                now()->parse($data['ends_on'])->toImmutable()->startOfDay(),
                $data['reason'],
            );
        } catch (LeaveRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('leave_requested', 'leave', $leave, $request->user());

        return back()->with('success', "{$leave->reference} sent for approval — {$leave->days} day(s).");
    }

    public function decide(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($request->user()->can('leave.approve'), 403);
        abort_unless($this->leave->mayDecideFor($request->user(), $leaveRequest), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $decided = $this->leave->decide($leaveRequest, $request->user(), $data['decision'], $data['note'] ?? null);
        } catch (LeaveRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record("leave_{$data['decision']}", 'leave', $decided, $request->user());

        return back()->with('success', "{$decided->reference} {$data['decision']}.");
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($request->user()->can('leave.request'), 403);

        try {
            $cancelled = $this->leave->cancel($leaveRequest, $request->user());
        } catch (LeaveRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('leave_cancelled', 'leave', $cancelled, $request->user());

        return back()->with('success', "{$cancelled->reference} withdrawn.");
    }

    /** @return array<string, mixed> */
    private function present(LeaveRequest $row): array
    {
        return [
            'id' => $row->getKey(),
            'reference' => $row->reference,
            'type' => $row->type->name ?? null,
            'employee' => $row->employee->name ?? null,
            'status' => $row->status,
            'starts_on' => $row->starts_on?->toDateString(),
            'ends_on' => $row->ends_on?->toDateString(),
            'days' => (string) $row->days,
            'reason' => $row->reason,
            'decision_note' => $row->decision_note,
            'started' => $row->starts_on?->isPast() ?? false,
        ];
    }
}
