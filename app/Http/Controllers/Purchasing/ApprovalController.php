<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Domain\Approvals\ApprovalEngine;
use App\Domain\Approvals\ApprovalOutcomeApplier;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly ApprovalOutcomeApplier $outcomes,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('purchasing.approve'), 403);

        $user = $request->user();

        $requests = ApprovalRequest::query()
            ->where('status', 'pending')
            ->with(['requester:id,name', 'flow:id,name'])
            ->orderBy('created_at')
            ->paginate(20)
            ->through(fn (ApprovalRequest $approval): array => [
                'id' => $approval->getKey(),
                'subject' => $this->outcomes->describe($approval),
                'flow' => $approval->flow->name ?? '—',
                'amount' => (string) $approval->amount,
                'formatted_amount' => $this->engine->describeAmount($approval),
                'requester' => $approval->requester->name ?? '—',
                'current_sequence' => $approval->current_sequence,
                'raised_at' => $approval->created_at?->toDayDateTimeString(),
                'blocked_reason' => $this->engine->reasonAgainst($approval, $user),
            ]);

        return Inertia::render('Purchasing/Approvals/Index', ['requests' => $requests]);
    }

    public function decide(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        abort_unless($request->user()->can('purchasing.approve'), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'return'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $comment = $data['comment'] ?? null;

        if ($data['decision'] !== 'approve' && ($comment === null || trim($comment) === '')) {
            return back()->withErrors(['comment' => 'Say why. A rejection with no reason is not a decision anyone can act on.']);
        }

        try {
            $decided = match ($data['decision']) {
                'approve' => $this->engine->approve($approvalRequest, $request->user(), $comment),
                'reject' => $this->engine->reject($approvalRequest, $request->user(), (string) $comment),
                default => $this->engine->returnForRevision($approvalRequest, $request->user(), (string) $comment),
            };

            $this->outcomes->apply($decided);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record($data['decision'], 'purchasing', $approvalRequest->refresh(), $request->user());

        return back()->with('success', 'Decision recorded.');
    }
}
