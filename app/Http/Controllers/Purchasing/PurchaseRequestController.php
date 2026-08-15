<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Domain\Approvals\ApprovalEngine;
use App\Domain\Approvals\ApprovalOutcomeApplier;
use App\Domain\Numbering\DocumentNumberService;
use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequest;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PurchaseRequestController extends Controller
{
    private const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'ordered', 'cancelled'];

    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalEngine $approvals,
        private readonly ApprovalOutcomeApplier $outcomes,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $term = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $requests = PurchaseRequest::query()
            ->visibleTo($request->user(), 'purchasing.view')
            ->when($term !== '', fn ($query) => $query->where('reference', 'ilike', "%{$term}%"))
            ->when(in_array($status, self::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->with(['requester:id,name', 'branch:id,name'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseRequest $record): array => [
                'id' => $record->getKey(),
                'reference' => $record->reference,
                'status' => $record->status,
                'requester' => $record->requester?->name,
                'branch' => $record->branch?->name,
                'items_count' => (int) $record->getAttribute('items_count'),
                'needed_by' => $record->needed_by?->toDateString(),
                'created_at' => $record->created_at?->toDateString(),
            ]);

        return Inertia::render('Purchasing/Requests/Index', [
            'requests' => $requests,
            'filters' => ['q' => $term, 'status' => $status],
            'statuses' => array_map(fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], self::STATUSES),
        ]);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): Response
    {
        $this->authorize('view', $purchaseRequest);

        $purchaseRequest->loadMissing(['items.variant.product:id,name', 'requester:id,name', 'branch:id,name']);

        $user = $request->user();

        return Inertia::render('Purchasing/Requests/Show', [
            'request' => [
                'id' => $purchaseRequest->getKey(),
                'reference' => $purchaseRequest->reference,
                'status' => $purchaseRequest->status,
                'requester' => $purchaseRequest->requester?->name,
                'branch' => $purchaseRequest->branch?->name,
                'needed_by' => $purchaseRequest->needed_by?->toDateString(),
                'note' => $purchaseRequest->note,
                'created_at' => $purchaseRequest->created_at?->toDayDateTimeString(),
            ],
            'items' => $purchaseRequest->items->map(fn (PurchaseRequestItem $item): array => [
                'id' => $item->getKey(),
                'sku' => $item->variant?->sku,
                'product' => $item->variant?->product?->name,
                'variant' => $item->variant?->name,
                'quantity' => (string) $item->quantity,
                'note' => $item->note,
            ])->all(),
            'approval' => $this->approvalPanel($purchaseRequest),
            'permissions' => [
                'submit' => $user->can('update', $purchaseRequest),
                'approve' => $user->can('approve', $purchaseRequest),
                'raise_order' => $user->can('create', PurchaseOrder::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PurchaseRequest::class);

        return Inertia::render('Purchasing/Requests/Create', [
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Branch $b): array => ['value' => $b->getKey(), 'label' => $b->name])->all(),
            'variants' => $this->variantOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'branch_id' => ['nullable', 'string', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'needed_by' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'string', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = DB::transaction(function () use ($data, $request): PurchaseRequest {
            $record = PurchaseRequest::create([
                'branch_id' => $data['branch_id'] ?? null,
                'requested_by' => $request->user()->getKey(),
                'reference' => $this->numbers->next('purchase_request', 'PR'),
                'status' => 'draft',
                'needed_by' => $data['needed_by'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                PurchaseRequestItem::create([
                    'purchase_request_id' => $record->getKey(),
                    'product_variant_id' => $line['product_variant_id'],
                    'quantity' => (string) $line['quantity'],
                    'note' => $line['note'] ?? null,
                ]);
            }

            $this->recorder->record('created', 'purchasing', $record, $request->user());

            return $record;
        });

        return redirect("/purchase-requests/{$record->getKey()}")
            ->with('success', "Purchase request {$record->reference} raised.");
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);

        if ($purchaseRequest->status !== 'draft') {
            return back()->with('error', "This request is already {$purchaseRequest->status} and cannot be submitted again.");
        }

        $flow = ApprovalFlow::query()
            ->where('approvable_type', PurchaseRequest::class)
            ->where('is_active', true)
            ->first();

        DB::transaction(function () use ($purchaseRequest, $request, $flow): void {
            if ($flow !== null) {
                $this->approvals->submit($purchaseRequest, $this->estimatedValue($purchaseRequest), $request->user());
            }

            $purchaseRequest->forceFill(['status' => 'pending'])->save();

            $this->recorder->record('submitted', 'purchasing', $purchaseRequest, $request->user());
        });

        return back()->with('success', $flow === null
            ? 'Submitted. No approval flow is configured, so someone with approval rights can approve it directly.'
            : 'Submitted for approval.');
    }

    public function decide(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('approve', $purchaseRequest);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        if ($purchaseRequest->status !== 'pending') {
            return back()->with('error', "Only a pending request can be decided, and this one is {$purchaseRequest->status}.");
        }

        $pending = $this->pendingApprovalFor($purchaseRequest);

        try {
            DB::transaction(function () use ($pending, $data, $request, $purchaseRequest): void {
                if ($pending !== null) {
                    $decided = $data['decision'] === 'approved'
                        ? $this->approvals->approve($pending, $request->user(), $data['comment'] ?? null)
                        : $this->approvals->reject($pending, $request->user(), (string) ($data['comment'] ?? 'Rejected.'));

                    $this->outcomes->apply($decided);

                    return;
                }

                $purchaseRequest->forceFill(['status' => $data['decision']])->save();
            });
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record($data['decision'], 'purchasing', $purchaseRequest->refresh(), $request->user());

        return back()->with('success', "Request {$data['decision']}.");
    }

    /** @return array<string, mixed>|null */
    private function approvalPanel(PurchaseRequest $purchaseRequest): ?array
    {
        $approval = ApprovalRequest::query()
            ->where('approvable_type', PurchaseRequest::class)
            ->where('approvable_id', $purchaseRequest->getKey())
            ->with(['actions.actor:id,name'])
            ->latest('created_at')
            ->first();

        return $approval === null ? null : [
            'id' => $approval->getKey(),
            'status' => $approval->status,
            'amount' => (string) $approval->amount,
            'current_sequence' => $approval->current_sequence,
            'actions' => $approval->actions->map(fn ($action): array => [
                'id' => $action->getKey(),
                'action' => $action->action,
                'comment' => $action->comment,
                'actor' => $action->actor->name ?? 'System',
                'at' => $action->created_at?->toDayDateTimeString(),
            ])->all(),
        ];
    }

    private function pendingApprovalFor(PurchaseRequest $purchaseRequest): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('approvable_type', PurchaseRequest::class)
            ->where('approvable_id', $purchaseRequest->getKey())
            ->where('status', 'pending')
            ->latest('created_at')
            ->first();
    }

    private function estimatedValue(PurchaseRequest $purchaseRequest): string
    {
        $total = Money::zero();

        foreach ($purchaseRequest->items()->with('variant')->get() as $item) {
            $cost = Money::of((string) ($item->variant->cost_price ?? '0'));
            $total = $total->plus($cost->times((string) $item->quantity));
        }

        return $total->toDecimal();
    }

    /** @return array<int, array{value: string, label: string, cost: string}> */
    private function variantOptions(): array
    {
        return ProductVariant::query()
            ->where('is_active', true)
            ->with('product:id,name')
            ->orderBy('sku')
            ->limit(500)
            ->get()
            ->map(fn (ProductVariant $v): array => [
                'value' => $v->getKey(),
                'label' => $v->sku.' — '.($v->product->name ?? '').' '.$v->name,
                'cost' => (string) $v->cost_price,
            ])
            ->all();
    }
}
