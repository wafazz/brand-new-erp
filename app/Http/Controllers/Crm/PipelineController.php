<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\PipelineRefused;
use App\Domain\Crm\PipelineService;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
        private readonly AuditRecorder $recorder,
    ) {}

    public function board(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);

        return Inertia::render('Crm/Board', [
            'columns' => $this->pipeline->board($request->user()),
            'followUps' => $this->pipeline->followUpsDue($request->user()),
            'can' => [
                'update' => $request->user()->can('leads.update'),
                'configure' => $request->user()->can('leads.configure'),
            ],
        ]);
    }

    public function stages(Request $request): Response
    {
        abort_unless($request->user()->can('leads.configure'), 403);

        return Inertia::render('Crm/Stages', [
            'stages' => PipelineStage::query()
                ->orderBy('sort')
                ->get()
                ->map(fn (PipelineStage $stage): array => [
                    'id' => $stage->getKey(),
                    'code' => $stage->code,
                    'name' => $stage->name,
                    'probability' => (string) $stage->probability,
                    'sort' => $stage->sort,
                    'is_won' => (bool) $stage->is_won,
                    'is_lost' => (bool) $stage->is_lost,
                    'leads' => Lead::query()->where('pipeline_stage_id', $stage->getKey())->count(),
                ])->all(),
        ]);
    }

    public function storeStage(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('leads.configure'), 403);

        $stage = PipelineStage::create($this->validatedStage($request, null));

        $this->recorder->record('stage_created', 'leads', $stage, $request->user());

        return back()->with('success', "Stage {$stage->name} added.");
    }

    public function updateStage(Request $request, PipelineStage $stage): RedirectResponse
    {
        abort_unless($request->user()->can('leads.configure'), 403);

        $stage->update($this->validatedStage($request, $stage));

        $this->recorder->record('stage_updated', 'leads', $stage->refresh(), $request->user());

        return back()->with('success', 'Stage updated.');
    }

    public function logContact(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'type' => ['required', Rule::in(PipelineService::CONTACT_TYPES)],
            'summary' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        try {
            $this->pipeline->logContact($lead, [
                'type' => $data['type'],
                'summary' => $data['summary'],
                'note' => $data['note'] ?? null,
                'follow_up_at' => isset($data['follow_up_at']) ? now()->parse($data['follow_up_at']) : null,
            ], $request->user());
        } catch (PipelineRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Contact logged.');
    }

    public function moveStage(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'pipeline_stage_id' => ['required', 'string', Rule::exists('pipeline_stages', 'id')->where('company_id', $companyId)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $stage = PipelineStage::query()->findOrFail($data['pipeline_stage_id']);

        try {
            $this->pipeline->moveToStage($lead, $stage, $request->user(), $data['note'] ?? null);
        } catch (PipelineRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Moved to {$stage->name}.");
    }

    /** @return array<string, mixed> */
    private function validatedStage(Request $request, ?PipelineStage $stage): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('pipeline_stages', 'code')
                ->where('company_id', $companyId)->ignore($stage?->getKey())],
            'name' => ['required', 'string', 'max:120'],
            'probability' => ['required', 'integer', 'min:0', 'max:100'],
            'sort' => ['required', 'integer', 'min:0'],
            'is_won' => ['required', 'boolean'],
            'is_lost' => ['required', 'boolean'],
        ]);

        if ($data['is_won'] && $data['is_lost']) {
            abort(422, 'A stage cannot be both won and lost.');
        }

        return $data;
    }
}
