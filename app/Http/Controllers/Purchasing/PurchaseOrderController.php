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
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\Warehouse;
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

class PurchaseOrderController extends Controller
{
    private const STATUSES = ['draft', 'pending', 'approved', 'partially_received', 'received', 'billed', 'closed', 'cancelled'];

    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalEngine $approvals,
        private readonly ApprovalOutcomeApplier $outcomes,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $term = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $orders = PurchaseOrder::query()
            ->visibleTo($request->user(), 'purchasing.view')
            ->when($term !== '', fn ($query) => $query->where('reference', 'ilike', "%{$term}%"))
            ->when(in_array($status, self::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->with(['supplier:id,code,name', 'branch:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseOrder $order): array => [
                'id' => $order->getKey(),
                'reference' => $order->reference,
                'status' => $order->status,
                'supplier' => $order->supplier?->name,
                'branch' => $order->branch?->name,
                'currency' => $order->currency,
                'total' => (string) $order->total,
                'expected_at' => $order->expected_at?->toDateString(),
            ]);

        return Inertia::render('Purchasing/Orders/Index', [
            'orders' => $orders,
            'filters' => ['q' => $term, 'status' => $status],
            'statuses' => array_map(
                fn (string $s): array => ['value' => $s, 'label' => ucfirst(str_replace('_', ' ', $s))],
                self::STATUSES
            ),
        ]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->loadMissing(['items', 'supplier:id,code,name', 'branch:id,name', 'warehouse:id,name']);

        $user = $request->user();

        return Inertia::render('Purchasing/Orders/Show', [
            'order' => [
                'id' => $purchaseOrder->getKey(),
                'reference' => $purchaseOrder->reference,
                'status' => $purchaseOrder->status,
                'supplier_id' => $purchaseOrder->supplier_id,
                'supplier' => $purchaseOrder->supplier?->name,
                'branch' => $purchaseOrder->branch?->name,
                'warehouse' => $purchaseOrder->warehouse?->name,
                'currency' => $purchaseOrder->currency,
                'subtotal' => (string) $purchaseOrder->subtotal,
                'tax_amount' => (string) $purchaseOrder->tax_amount,
                'total' => (string) $purchaseOrder->total,
                'expected_at' => $purchaseOrder->expected_at?->toDateString(),
                'note' => $purchaseOrder->note,
            ],
            'items' => $purchaseOrder->items->map(fn (PurchaseOrderItem $item): array => [
                'id' => $item->getKey(),
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'quantity' => (string) $item->quantity,
                'quantity_received' => (string) $item->quantity_received,
                'outstanding' => bcsub((string) $item->quantity, (string) $item->quantity_received, 4),
                'unit_cost' => (string) $item->unit_cost,
                'line_total' => (string) $item->line_total,
            ])->all(),
            'receipts' => GoodsReceipt::query()
                ->where('purchase_order_id', $purchaseOrder->getKey())
                ->withCount('items')
                ->orderByDesc('received_at')
                ->get()
                ->map(fn (GoodsReceipt $receipt): array => [
                    'id' => $receipt->getKey(),
                    'reference' => $receipt->reference,
                    'supplier_do_number' => $receipt->supplier_do_number,
                    'items_count' => (int) $receipt->getAttribute('items_count'),
                    'received_at' => $receipt->received_at?->toDayDateTimeString(),
                ])->all(),
            'bills' => SupplierBill::query()
                ->where('purchase_order_id', $purchaseOrder->getKey())
                ->orderByDesc('billed_at')
                ->get()
                ->map(fn (SupplierBill $bill): array => [
                    'id' => $bill->getKey(),
                    'reference' => $bill->reference,
                    'supplier_invoice_number' => $bill->supplier_invoice_number,
                    'status' => $bill->status,
                    'total' => (string) $bill->total,
                ])->all(),
            'approval' => $this->approvalPanel($purchaseOrder),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Warehouse $w): array => ['value' => $w->getKey(), 'label' => $w->name])->all(),
            'permissions' => [
                'submit' => $user->can('update', $purchaseOrder),
                'approve' => $user->can('approve', $purchaseOrder),
                'receive' => $user->can('purchasing.receive'),
                'bill' => $user->can('create', SupplierBill::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PurchaseOrder::class);

        $fromRequest = $request->query('from_request');

        return Inertia::render('Purchasing/Orders/Create', [
            'suppliers' => Supplier::query()->where('status', 'active')->orderBy('name')->get()
                ->map(fn (Supplier $s): array => ['value' => $s->getKey(), 'label' => $s->code.' — '.$s->name])->all(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Branch $b): array => ['value' => $b->getKey(), 'label' => $b->name])->all(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Warehouse $w): array => ['value' => $w->getKey(), 'label' => $w->name])->all(),
            'variants' => ProductVariant::query()
                ->where('is_active', true)
                ->with('product:id,name')
                ->orderBy('sku')
                ->limit(500)
                ->get()
                ->map(fn (ProductVariant $v): array => [
                    'value' => $v->getKey(),
                    'label' => $v->sku.' — '.($v->product->name ?? '').' '.$v->name,
                    'cost' => (string) $v->cost_price,
                    'sku' => $v->sku,
                    'product_name' => $v->product->name ?? $v->name,
                ])->all(),
            'seed' => $fromRequest === null ? null : $this->seedFromRequest((string) $fromRequest),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'supplier_id' => ['required', 'string', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'branch_id' => ['nullable', 'string', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'purchase_request_id' => ['nullable', 'string', Rule::exists('purchase_requests', 'id')->where('company_id', $companyId)],
            'currency' => ['required', 'string', 'size:3'],
            'expected_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'string', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $order = DB::transaction(function () use ($data, $request): PurchaseOrder {
            $order = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'purchase_request_id' => $data['purchase_request_id'] ?? null,
                'created_by' => $request->user()->getKey(),
                'reference' => $this->numbers->next('purchase_order', 'PO'),
                'status' => 'draft',
                'currency' => strtoupper($data['currency']),
                'expected_at' => $data['expected_at'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $variant = ProductVariant::query()->with('product:id,name')->findOrFail($line['product_variant_id']);
                $lineTotal = Money::of((string) $line['unit_cost'], $order->currency)->times((string) $line['quantity']);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'sku' => $variant->sku,
                    'product_name' => $variant->product->name ?? $variant->name,
                    'quantity' => (string) $line['quantity'],
                    'unit_cost' => (string) $line['unit_cost'],
                    'line_total' => $lineTotal->toDecimal(),
                ]);
            }

            $this->recalculate($order);

            if (isset($data['purchase_request_id'])) {
                PurchaseRequest::query()->whereKey($data['purchase_request_id'])
                    ->update(['status' => 'ordered']);
            }

            $this->recorder->record('created', 'purchasing', $order, $request->user());

            return $order;
        });

        return redirect("/purchase-orders/{$order->getKey()}")
            ->with('success', "Purchase order {$order->reference} raised.");
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        if ($purchaseOrder->status !== 'draft') {
            return back()->with('error', "This order is {$purchaseOrder->status} and cannot be submitted again.");
        }

        $flow = ApprovalFlow::query()
            ->where('approvable_type', PurchaseOrder::class)
            ->where('is_active', true)
            ->first();

        DB::transaction(function () use ($purchaseOrder, $request, $flow): void {
            if ($flow !== null) {
                $this->approvals->submit($purchaseOrder, (string) $purchaseOrder->total, $request->user());
            }

            $purchaseOrder->forceFill(['status' => 'pending'])->save();

            $this->recorder->record('submitted', 'purchasing', $purchaseOrder, $request->user());
        });

        return back()->with('success', $flow === null
            ? 'Submitted. No approval flow is configured, so someone with approval rights can approve it directly.'
            : 'Submitted for approval.');
    }

    public function decide(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('approve', $purchaseOrder);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        if ($purchaseOrder->status !== 'pending') {
            return back()->with('error', "Only a pending order can be decided, and this one is {$purchaseOrder->status}.");
        }

        $pending = ApprovalRequest::query()
            ->where('approvable_type', PurchaseOrder::class)
            ->where('approvable_id', $purchaseOrder->getKey())
            ->where('status', 'pending')
            ->latest('created_at')
            ->first();

        try {
            DB::transaction(function () use ($pending, $data, $request, $purchaseOrder): void {
                if ($pending !== null) {
                    $decided = $data['decision'] === 'approved'
                        ? $this->approvals->approve($pending, $request->user(), $data['comment'] ?? null)
                        : $this->approvals->reject($pending, $request->user(), (string) ($data['comment'] ?? 'Rejected.'));

                    $this->outcomes->apply($decided);

                    return;
                }

                $purchaseOrder->forceFill([
                    'status' => $data['decision'] === 'approved' ? 'approved' : 'cancelled',
                ])->save();
            });
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record($data['decision'], 'purchasing', $purchaseOrder->refresh(), $request->user());

        return back()->with('success', "Order {$data['decision']}.");
    }

    private function recalculate(PurchaseOrder $order): void
    {
        $subtotal = Money::zero($order->currency);
        $tax = Money::zero($order->currency);

        foreach ($order->items()->get() as $item) {
            $subtotal = $subtotal->plus(Money::of((string) $item->line_total, $order->currency));
            $tax = $tax->plus(Money::of((string) $item->tax_amount, $order->currency));
        }

        $order->forceFill([
            'subtotal' => $subtotal->toDecimal(),
            'tax_amount' => $tax->toDecimal(),
            'total' => $subtotal->plus($tax)->toDecimal(),
        ])->save();
    }

    /** @return array<string, mixed>|null */
    private function approvalPanel(PurchaseOrder $order): ?array
    {
        $approval = ApprovalRequest::query()
            ->where('approvable_type', PurchaseOrder::class)
            ->where('approvable_id', $order->getKey())
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

    /** @return array<string, mixed>|null */
    private function seedFromRequest(string $purchaseRequestId): ?array
    {
        $record = PurchaseRequest::query()
            ->with('items.variant.product:id,name')
            ->find($purchaseRequestId);

        if ($record === null || $record->status !== 'approved') {
            return null;
        }

        return [
            'purchase_request_id' => $record->getKey(),
            'reference' => $record->reference,
            'branch_id' => $record->branch_id,
            'lines' => $record->items->map(fn ($item): array => [
                'product_variant_id' => $item->product_variant_id,
                'quantity' => (string) $item->quantity,
                'unit_cost' => (string) ($item->variant->cost_price ?? '0'),
            ])->all(),
        ];
    }
}
