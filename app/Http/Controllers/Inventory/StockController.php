<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockReason;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class StockController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $term = trim((string) $request->query('q', ''));
        $warehouseId = (string) $request->query('warehouse', '');
        $lowOnly = $request->boolean('low');

        $lines = Stock::query()
            ->when($warehouseId !== '', fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($lowOnly, fn ($query) => $query->whereNotNull('low_stock_threshold')
                ->whereColumn('on_hand', '<=', 'low_stock_threshold'))
            ->when($term !== '', fn ($query) => $query->whereHas('variant', fn ($q) => $q
                ->where('sku', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%")))
            ->with(['variant:id,sku,name,product_id', 'variant.product:id,name', 'warehouse:id,name'])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Stock $stock): array => [
                'id' => $stock->getKey(),
                'sku' => $stock->variant?->sku,
                'product' => $stock->variant?->product?->name,
                'variant' => $stock->variant?->name,
                'warehouse' => $stock->warehouse?->name,
                'on_hand' => (string) $stock->on_hand,
                'reserved' => (string) $stock->reserved,
                'available' => $this->inventory->available($stock),
                'low_stock_threshold' => $stock->low_stock_threshold === null ? null : (string) $stock->low_stock_threshold,
                'is_low' => $stock->low_stock_threshold !== null
                    && bccomp((string) $stock->on_hand, (string) $stock->low_stock_threshold, 4) <= 0,
            ]);

        return Inertia::render('Inventory/Stock/Index', [
            'lines' => $lines,
            'filters' => ['q' => $term, 'warehouse' => $warehouseId, 'low' => $lowOnly],
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Warehouse $w): array => ['value' => $w->getKey(), 'label' => $w->name])->all(),
            'can' => [
                'adjust' => $request->user()->can('inventory.adjust'),
            ],
        ]);
    }

    public function show(Request $request, Stock $stock): Response
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $stock->loadMissing(['variant:id,sku,name,product_id', 'variant.product:id,name', 'warehouse:id,name']);

        return Inertia::render('Inventory/Stock/Show', [
            'stock' => [
                'id' => $stock->getKey(),
                'sku' => $stock->variant?->sku,
                'product' => $stock->variant?->product?->name,
                'variant' => $stock->variant?->name,
                'warehouse' => $stock->warehouse?->name,
                'on_hand' => (string) $stock->on_hand,
                'reserved' => (string) $stock->reserved,
                'available' => $this->inventory->available($stock),
                'low_stock_threshold' => $stock->low_stock_threshold === null ? null : (string) $stock->low_stock_threshold,
            ],
            'movements' => StockMovement::query()
                ->where('stock_id', $stock->getKey())
                ->with('actor:id,name')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (StockMovement $movement): array => [
                    'id' => $movement->getKey(),
                    'reason' => $movement->reason,
                    'quantity' => (string) $movement->quantity_delta,
                    'balance_after' => (string) $movement->balance_after,
                    'note' => $movement->note,
                    'actor' => $movement->actor->name ?? 'System',
                    'at' => $movement->created_at?->toDayDateTimeString(),
                ])->all(),
            'reasons' => array_map(
                fn (StockReason $reason): array => ['value' => $reason->value, 'label' => ucfirst(str_replace('_', ' ', $reason->value))],
                [StockReason::Adjustment, StockReason::StockTake, StockReason::Damaged, StockReason::Returned]
            ),
            'can' => [
                'adjust' => $request->user()->can('inventory.adjust'),
            ],
        ]);
    }

    public function adjust(Request $request, Stock $stock): RedirectResponse
    {
        abort_unless($request->user()->can('inventory.adjust'), 403);

        $data = $request->validate([
            'delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', Rule::in(['adjustment', 'stock_take', 'damaged', 'returned'])],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->inventory->adjust(
                $stock,
                (string) $data['delta'],
                StockReason::from($data['reason']),
                null,
                $request->user(),
                $data['note'],
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Stock adjusted.');
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('inventory.adjust'), 403);

        return Inertia::render('Inventory/Stock/Create', [
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
                ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('inventory.adjust'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'product_variant_id' => ['required', 'string', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $stock = $this->inventory->lineFor($data['product_variant_id'], $warehouse);

        if (isset($data['low_stock_threshold'])) {
            $stock->update(['low_stock_threshold' => $data['low_stock_threshold']]);
        }

        return redirect("/inventory/{$stock->getKey()}")->with('success', 'Stock line ready.');
    }
}
