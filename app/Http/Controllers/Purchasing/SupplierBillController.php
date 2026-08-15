<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Domain\Numbering\DocumentNumberService;
use App\Domain\Purchasing\BillNotPayable;
use App\Domain\Purchasing\PurchasingService;
use App\Domain\Purchasing\ThreeWayMatch;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\SupplierPayment;
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

class SupplierBillController extends Controller
{
    private const STATUSES = ['draft', 'matched', 'disputed', 'approved', 'paid', 'cancelled'];

    public function __construct(
        private readonly PurchasingService $purchasing,
        private readonly ThreeWayMatch $matcher,
        private readonly DocumentNumberService $numbers,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SupplierBill::class);

        $term = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $bills = SupplierBill::query()
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('reference', 'ilike', "%{$term}%")
                    ->orWhere('supplier_invoice_number', 'ilike', "%{$term}%");
            }))
            ->when(in_array($status, self::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->with(['supplier:id,name', 'purchaseOrder:id,reference'])
            ->orderByDesc('billed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierBill $bill): array => [
                'id' => $bill->getKey(),
                'reference' => $bill->reference,
                'supplier_invoice_number' => $bill->supplier_invoice_number,
                'supplier' => $bill->supplier?->name,
                'purchase_order' => $bill->purchaseOrder?->reference,
                'status' => $bill->status,
                'currency' => $bill->currency,
                'total' => (string) $bill->total,
                'paid_amount' => (string) $bill->paid_amount,
                'outstanding' => bcsub((string) $bill->total, (string) $bill->paid_amount, 4),
                'billed_at' => $bill->billed_at?->toDateString(),
                'due_at' => $bill->due_at?->toDateString(),
            ]);

        return Inertia::render('Purchasing/Bills/Index', [
            'bills' => $bills,
            'filters' => ['q' => $term, 'status' => $status],
            'statuses' => array_map(fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], self::STATUSES),
        ]);
    }

    public function show(Request $request, SupplierBill $supplierBill): Response
    {
        $this->authorize('view', $supplierBill);

        $supplierBill->loadMissing([
            'items.purchaseOrderItem',
            'supplier:id,name',
            'purchaseOrder:id,reference',
        ]);

        $match = $this->matcher->match($supplierBill);
        $user = $request->user();

        return Inertia::render('Purchasing/Bills/Show', [
            'bill' => [
                'id' => $supplierBill->getKey(),
                'reference' => $supplierBill->reference,
                'supplier_invoice_number' => $supplierBill->supplier_invoice_number,
                'supplier' => $supplierBill->supplier?->name,
                'purchase_order_id' => $supplierBill->purchase_order_id,
                'purchase_order' => $supplierBill->purchaseOrder?->reference,
                'status' => $supplierBill->status,
                'currency' => $supplierBill->currency,
                'subtotal' => (string) $supplierBill->subtotal,
                'tax_amount' => (string) $supplierBill->tax_amount,
                'total' => (string) $supplierBill->total,
                'paid_amount' => (string) $supplierBill->paid_amount,
                'outstanding' => bcsub((string) $supplierBill->total, (string) $supplierBill->paid_amount, 4),
                'billed_at' => $supplierBill->billed_at?->toDateString(),
                'due_at' => $supplierBill->due_at?->toDateString(),
            ],
            'items' => $supplierBill->items->map(fn (SupplierBillItem $item): array => [
                'id' => $item->getKey(),
                'sku' => $item->purchaseOrderItem?->sku,
                'product_name' => $item->purchaseOrderItem?->product_name,
                'ordered_unit_cost' => $item->purchaseOrderItem === null ? null : (string) $item->purchaseOrderItem->unit_cost,
                'quantity' => (string) $item->quantity,
                'unit_cost' => (string) $item->unit_cost,
                'line_total' => (string) $item->line_total,
            ])->all(),
            'match' => [
                'matched' => $match->matched,
                'reason' => $match->reason(),
                'discrepancies' => $match->toArray(),
            ],
            'payments' => SupplierPayment::query()
                ->where('supplier_bill_id', $supplierBill->getKey())
                ->with('payer:id,name')
                ->orderByDesc('paid_at')
                ->get()
                ->map(fn (SupplierPayment $payment): array => [
                    'id' => $payment->getKey(),
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'amount' => (string) $payment->amount,
                    'payer' => $payment->payer->name ?? 'System',
                    'paid_at' => $payment->paid_at?->toDayDateTimeString(),
                ])->all(),
            'permissions' => [
                'approve' => $user->can('approve', $supplierBill),
                'pay' => $user->can('pay', $supplierBill),
            ],
        ]);
    }

    public function create(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('create', SupplierBill::class);

        $purchaseOrder->loadMissing(['items', 'supplier:id,name']);

        return Inertia::render('Purchasing/Bills/Create', [
            'order' => [
                'id' => $purchaseOrder->getKey(),
                'reference' => $purchaseOrder->reference,
                'supplier' => $purchaseOrder->supplier?->name,
                'currency' => $purchaseOrder->currency,
            ],
            'lines' => $purchaseOrder->items->map(function (PurchaseOrderItem $item): array {
                $received = (string) GoodsReceiptItem::query()
                    ->where('purchase_order_item_id', $item->getKey())
                    ->sum('quantity');

                return [
                    'purchase_order_item_id' => $item->getKey(),
                    'sku' => $item->sku,
                    'product_name' => $item->product_name,
                    'ordered' => (string) $item->quantity,
                    'received' => $received,
                    'already_billed' => (string) $item->quantity_billed,
                    'unit_cost' => (string) $item->unit_cost,
                ];
            })->all(),
        ]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('create', SupplierBill::class);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'supplier_invoice_number' => ['required', 'string', 'max:60'],
            'billed_at' => ['required', 'date'],
            'due_at' => ['nullable', 'date'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['required', 'string', Rule::exists('purchase_order_items', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $bill = DB::transaction(function () use ($purchaseOrder, $data, $request): SupplierBill {
            $bill = SupplierBill::create([
                'purchase_order_id' => $purchaseOrder->getKey(),
                'supplier_id' => $purchaseOrder->supplier_id,
                'recorded_by' => $request->user()->getKey(),
                'reference' => $this->numbers->next('supplier_bill', 'BIL'),
                'supplier_invoice_number' => $data['supplier_invoice_number'],
                'status' => 'draft',
                'currency' => $purchaseOrder->currency,
                'billed_at' => $data['billed_at'],
                'due_at' => $data['due_at'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $lineTotal = Money::of((string) $line['unit_cost'], $bill->currency)->times((string) $line['quantity']);

                SupplierBillItem::create([
                    'supplier_bill_id' => $bill->getKey(),
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'quantity' => (string) $line['quantity'],
                    'unit_cost' => (string) $line['unit_cost'],
                    'line_total' => $lineTotal->toDecimal(),
                ]);
            }

            $bill->forceFill(['tax_amount' => (string) ($data['tax_amount'] ?? '0')])->save();

            $this->purchasing->recalculateBill($bill->refresh());
            $this->recorder->record('created', 'purchasing', $bill, $request->user());

            return $bill;
        });

        return redirect("/supplier-bills/{$bill->getKey()}")
            ->with('success', "Bill {$bill->reference} recorded. Check the three-way match before approving it.");
    }

    public function approve(Request $request, SupplierBill $supplierBill): RedirectResponse
    {
        $this->authorize('approve', $supplierBill);

        if (! in_array($supplierBill->status, ['draft', 'matched', 'disputed'], true)) {
            return back()->with('error', "This bill is {$supplierBill->status} and cannot be approved again.");
        }

        try {
            $this->purchasing->assertBillPayable($supplierBill);
        } catch (BillNotPayable $exception) {
            $supplierBill->forceFill(['status' => 'disputed'])->save();

            return back()->with('error', $exception->getMessage());
        }

        $supplierBill->forceFill(['status' => 'approved'])->save();

        $this->recorder->record('approved', 'purchasing', $supplierBill, $request->user());

        return back()->with('success', 'Bill matched and approved for payment.');
    }

    public function pay(Request $request, SupplierBill $supplierBill): RedirectResponse
    {
        $this->authorize('pay', $supplierBill);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['bank_transfer', 'cash', 'cheque', 'card'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        if ($supplierBill->status !== 'approved') {
            return back()->with('error', 'Only an approved bill can be paid. Approve it first — that is what runs the three-way match.');
        }

        $outstanding = Money::of(
            bcsub((string) $supplierBill->total, (string) $supplierBill->paid_amount, 4),
            $supplierBill->currency
        );

        $amount = Money::of((string) $data['amount'], $supplierBill->currency);

        if ($amount->greaterThan($outstanding)) {
            return back()->with('error', "This bill has {$outstanding->format()} outstanding and {$amount->format()} was offered.");
        }

        try {
            DB::transaction(function () use ($supplierBill, $amount, $data, $request): void {
                $locked = SupplierBill::query()->lockForUpdate()->findOrFail($supplierBill->getKey());

                SupplierPayment::create([
                    'supplier_bill_id' => $locked->getKey(),
                    'paid_by' => $request->user()->getKey(),
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'amount' => $amount->toDecimal(),
                    'paid_at' => now(),
                ]);

                $paid = Money::of((string) $locked->paid_amount, $locked->currency)->plus($amount);

                $locked->forceFill([
                    'paid_amount' => $paid->toDecimal(),
                    'status' => bccomp($paid->toDecimal(), (string) $locked->total, 4) >= 0 ? 'paid' : $locked->status,
                ])->save();
            });
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('paid', 'purchasing', $supplierBill->refresh(), $request->user());

        return back()->with('success', 'Payment recorded.');
    }
}
