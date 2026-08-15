<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Numbering\DocumentNumberService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    private const STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);

        $term = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $leads = Lead::query()
            ->visibleTo($request->user(), 'leads.view')
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', "%{$term}%")
                    ->orWhere('reference', 'ilike', "%{$term}%")
                    ->orWhere('phone', 'ilike', "%{$term}%");
            }))
            ->when(in_array($status, self::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->with(['assignee:id,name', 'stage:id,name'])
            ->orderByDesc('captured_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Lead $lead): array => [
                'id' => $lead->getKey(),
                'reference' => $lead->reference,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'status' => $lead->status,
                'stage' => $lead->stage?->name,
                'assignee' => $lead->assignee?->name,
                'estimated_value' => (string) $lead->estimated_value,
                'captured_at' => $lead->captured_at?->toDateString(),
                'converted_order_id' => $lead->converted_order_id,
            ]);

        return Inertia::render('Sales/Leads/Index', [
            'leads' => $leads,
            'filters' => ['q' => $term, 'status' => $status],
            'statuses' => array_map(fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], self::STATUSES),
        ]);
    }

    public function show(Request $request, Lead $lead): Response
    {
        $this->authorize('view', $lead);

        $lead->loadMissing(['assignee:id,name', 'stage:id,name', 'branch:id,name']);

        return Inertia::render('Sales/Leads/Show', [
            'lead' => [
                'id' => $lead->getKey(),
                'reference' => $lead->reference,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'status' => $lead->status,
                'stage' => $lead->stage?->name,
                'assignee' => $lead->assignee?->name,
                'branch' => $lead->branch?->name,
                'estimated_value' => (string) $lead->estimated_value,
                'captured_at' => $lead->captured_at?->toDayDateTimeString(),
                'converted_at' => $lead->converted_at?->toDayDateTimeString(),
                'converted_order_id' => $lead->converted_order_id,
                'note' => $lead->note,
            ],
            'activities' => LeadActivity::query()
                ->where('lead_id', $lead->getKey())
                ->with('actor:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (LeadActivity $activity): array => [
                    'id' => $activity->getKey(),
                    'type' => $activity->type,
                    'summary' => $activity->summary,
                    'actor' => $activity->actor->name ?? 'System',
                    'at' => $activity->created_at?->toDayDateTimeString(),
                ])->all(),
            'permissions' => [
                'update' => $request->user()->can('update', $lead),
                'convert' => $request->user()->can('convert', $lead),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Lead::class);

        return Inertia::render('Sales/Leads/Create', $this->references());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Lead::class);

        $data = $this->validated($request);

        $lead = DB::transaction(function () use ($data, $request): Lead {
            $lead = Lead::create([
                ...$data,
                'reference' => $data['reference'] ?? $this->numbers->next('lead', 'LD'),
                'assigned_to' => $data['assigned_to'] ?? $request->user()->getKey(),
                'captured_at' => now(),
            ]);

            $this->recorder->record('created', 'leads', $lead, $request->user());

            return $lead;
        });

        return redirect("/leads/{$lead->getKey()}")->with('success', "Lead {$lead->reference} captured.");
    }

    public function edit(Request $request, Lead $lead): Response
    {
        $this->authorize('update', $lead);

        return Inertia::render('Sales/Leads/Edit', [
            ...$this->references(),
            'lead' => [
                'id' => $lead->getKey(),
                'reference' => $lead->reference,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'status' => $lead->status,
                'pipeline_stage_id' => $lead->pipeline_stage_id,
                'assigned_to' => $lead->assigned_to,
                'branch_id' => $lead->branch_id,
                'estimated_value' => (string) $lead->estimated_value,
                'note' => $lead->note,
            ],
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $data = $this->validated($request);

        if ($lead->converted_order_id !== null && $data['status'] !== $lead->status) {
            return back()->with('error', 'This lead has already been converted, so its status is settled.');
        }

        DB::transaction(function () use ($lead, $data, $request): void {
            $lead->update($data);
            $this->recorder->record('updated', 'leads', $lead, $request->user());
        });

        return redirect("/leads/{$lead->getKey()}")->with('success', 'Lead updated.');
    }

    /** @return array<string, mixed> */
    private function references(): array
    {
        return [
            'stages' => PipelineStage::query()->orderBy('sort')->get()
                ->map(fn (PipelineStage $s): array => ['value' => $s->getKey(), 'label' => $s->name])->all(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Branch $b): array => ['value' => $b->getKey(), 'label' => $b->name])->all(),
            'assignees' => User::query()
                ->whereHas('memberships', fn ($query) => $query->where('is_active', true))
                ->orderBy('name')
                ->limit(200)
                ->get()
                ->map(fn (User $u): array => ['value' => $u->getKey(), 'label' => $u->name])->all(),
            'statuses' => array_map(fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], self::STATUSES),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        return $request->validate([
            'reference' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'pipeline_stage_id' => ['nullable', 'string', Rule::exists('pipeline_stages', 'id')->where('company_id', $companyId)],
            'assigned_to' => ['nullable', 'string', Rule::exists('users', 'id')],
            'branch_id' => ['nullable', 'string', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'estimated_value' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
