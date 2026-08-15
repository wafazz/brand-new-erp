<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\CostingService;
use App\Domain\Purchasing\PurchasingService;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptCost;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly PurchasingService $purchasing,
        private readonly CostingService $costing,
        private readonly AuditRecorder $recorder,
    ) {}

    public function show(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        abort_unless($request->user()->can('purchasing.view'), 403);

        $goodsReceipt->loadMissing([
            'items.purchaseOrderItem',
            'items.variant.product:id,name',
            'purchaseOrder:id,reference,currency,supplier_id',
            'purchaseOrder.supplier:id,name',
            'warehouse:id,name',
            'receiver:id,name',
        ]);

        $currency = $goodsReceipt->purchaseOrder->currency ?? 'MYR';

        return Inertia::render('Purchasing/Receipts/Show', [
            'receipt' => [
                'id' => $goodsReceipt->getKey(),
                'reference' => $goodsReceipt->reference,
                'supplier_do_number' => $goodsReceipt->supplier_do_number,
                'purchase_order_id' => $goodsReceipt->purchase_order_id,
                'purchase_order' => $goodsReceipt->purchaseOrder?->reference,
                'supplier' => $goodsReceipt->purchaseOrder?->supplier?->name,
                'warehouse' => $goodsReceipt->warehouse?->name,
                'receiver' => $goodsReceipt->receiver?->name,
                'received_at' => $goodsReceipt->received_at?->toDayDateTimeString(),
                'currency' => $currency,
                'note' => $goodsReceipt->note,
            ],
            'items' => $goodsReceipt->items->map(fn (GoodsReceiptItem $item): array => [
                'id' => $item->getKey(),
                'sku' => $item->variant?->sku,
                'product' => $item->variant?->product?->name,
                'quantity' => (string) $item->quantity,
                'unit_cost' => (string) $item->unit_cost,
                'landed_unit_cost' => $item->landed_unit_cost === null ? null : (string) $item->landed_unit_cost,
                'landed_cost_basis' => $item->landed_cost_basis,
            ])->all(),
            'costs' => GoodsReceiptCost::query()
                ->where('goods_receipt_id', $goodsReceipt->getKey())
                ->orderBy('created_at')
                ->get()
                ->map(fn (GoodsReceiptCost $cost): array => [
                    'id' => $cost->getKey(),
                    'kind' => $cost->kind,
                    'allocation' => $cost->allocation,
                    'amount' => (string) $cost->amount,
                    'note' => $cost->note,
                ])->all(),
            'permissions' => [
                'add_cost' => $request->user()->can('purchasing.receive'),
            ],
        ]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($request->user()->can('purchasing.receive'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'supplier_do_number' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['required', 'string', Rule::exists('purchase_order_items', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        if (! in_array($purchaseOrder->status, ['approved', 'partially_received'], true)) {
            return back()->with('error', "Goods can only be received against an approved order, and this one is {$purchaseOrder->status}.");
        }

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

        try {
            $receipt = $this->purchasing->receiveGoods(
                $purchaseOrder,
                $warehouse,
                array_map(
                    static fn (array $line): array => [
                        'purchase_order_item_id' => $line['purchase_order_item_id'],
                        'quantity' => (string) $line['quantity'],
                    ],
                    $data['lines']
                ),
                $request->user(),
                $data['supplier_do_number'] ?? null,
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('received', 'purchasing', $receipt, $request->user());

        return redirect("/goods-receipts/{$receipt->getKey()}")
            ->with('success', "Goods receipt {$receipt->reference} recorded and stock updated.");
    }

    public function addCost(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        abort_unless($request->user()->can('purchasing.receive'), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['freight', 'duty', 'insurance', 'handling', 'other'])],
            'allocation' => ['required', Rule::in(['by_value', 'by_quantity', 'by_weight'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->costing->addLandedCost(
                $goodsReceipt,
                $data['kind'],
                (string) $data['amount'],
                $data['allocation'],
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('landed_cost_added', 'purchasing', $goodsReceipt, $request->user());

        return back()->with('success', 'Landed cost recorded and apportioned across the receipt.');
    }
}
