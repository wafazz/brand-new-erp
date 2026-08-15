<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domain\Pos\PosService;
use App\Domain\Pos\TillRefused;
use App\Enums\ExceptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TillController extends Controller
{
    public function __construct(
        private readonly PosService $pos,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('pos.view'), 403);

        $mine = PosSession::query()
            ->where('status', 'open')
            ->where('opened_by', $request->user()->getKey())
            ->with('register:id,name')
            ->first();

        return Inertia::render('Pos/Index', [
            'openSession' => $mine === null ? null : $this->sessionPayload($mine),
            'registers' => PosRegister::query()
                ->where('is_active', true)
                ->with(['branch:id,name', 'warehouse:id,name'])
                ->orderBy('name')
                ->get()
                ->map(fn (PosRegister $register): array => [
                    'id' => $register->getKey(),
                    'code' => $register->code,
                    'name' => $register->name,
                    'branch' => $register->branch->name ?? null,
                    'warehouse' => $register->warehouse->name ?? null,
                    'busy' => $register->openSession() !== null,
                ])->all(),
            'recent' => PosSession::query()
                ->visibleTo($request->user(), 'pos.view')
                ->with(['register:id,name', 'cashier:id,name'])
                ->orderByDesc('opened_at')
                ->limit(10)
                ->get()
                ->map(fn (PosSession $session): array => [
                    'id' => $session->getKey(),
                    'reference' => $session->reference,
                    'register' => $session->register->name ?? null,
                    'cashier' => $session->cashier->name ?? null,
                    'status' => $session->status,
                    'variance' => $session->variance === null ? null : (string) $session->variance,
                    'opened_at' => $session->opened_at?->toDayDateTimeString(),
                ])->all(),
            'can' => ['sell' => $request->user()->can('pos.sell')],
        ]);
    }

    public function show(Request $request, PosSession $session): Response
    {
        abort_unless($request->user()->can('pos.view'), 403);
        $this->authorizeSession($request, $session);

        return Inertia::render('Pos/Session', [
            'session' => $this->sessionPayload($session),
            'variants' => $this->sellableVariants($session),
            'customers' => Customer::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(200)
                ->get()
                ->map(fn (Customer $c): array => ['value' => $c->getKey(), 'label' => $c->code.' — '.$c->name])
                ->all(),
            'sales' => Order::query()
                ->where('pos_session_id', $session->getKey())
                ->with('items')
                ->orderByDesc('placed_at')
                ->limit(25)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'total' => (string) $order->total,
                    'currency' => $order->currency,
                    'placed_at' => $order->placed_at?->format('H:i'),
                    'refunded' => $order->exception_status === ExceptionStatus::Returned,
                    'returned_amount' => (string) $order->returned_amount,
                    'lines' => $order->items->map(fn ($item): array => [
                        'id' => $item->getKey(),
                        'sku' => $item->sku,
                        'name' => trim((string) $item->product_name.' '.(string) $item->variant_name),
                        'outstanding' => bcsub((string) $item->quantity, (string) $item->quantity_returned, 4),
                    ])->all(),
                ])->all(),
            'movements' => PosCashMovement::query()
                ->where('pos_session_id', $session->getKey())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (PosCashMovement $movement): array => [
                    'id' => $movement->getKey(),
                    'kind' => $movement->kind,
                    'amount' => (string) $movement->amount,
                    'reason' => $movement->reason,
                    'at' => $movement->created_at?->format('H:i'),
                ])->all(),
            'can' => [
                'sell' => $request->user()->can('pos.sell'),
                'manage' => $request->user()->can('pos.manage'),
            ],
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sell'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'pos_register_id' => ['required', 'string', Rule::exists('pos_registers', 'id')->where('company_id', $companyId)],
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        $register = PosRegister::query()->findOrFail($data['pos_register_id']);

        try {
            $session = $this->pos->openSession($register, $request->user(), (string) $data['opening_float']);
        } catch (TillRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('till_opened', 'pos', $session, $request->user());

        return redirect("/pos/{$session->getKey()}")->with('success', "Till {$session->reference} open.");
    }

    public function sell(Request $request, PosSession $session): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sell'), 403);
        $this->authorizeSession($request, $session);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'customer_id' => ['nullable', 'string', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'string', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.method' => ['required', Rule::in(['cash', 'card', 'ewallet'])],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0'],
            'tenders.*.reference' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $order = $this->pos->sell(
                $session,
                array_map(
                    static fn (array $l): array => ['variant_id' => $l['variant_id'], 'quantity' => (string) $l['quantity']],
                    $data['lines']
                ),
                array_map(
                    static fn (array $t): array => [
                        'method' => $t['method'],
                        'amount' => (string) $t['amount'],
                        'reference' => $t['reference'] ?? null,
                    ],
                    $data['tenders']
                ),
                $request->user(),
                $data['customer_id'] ?? null,
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Sale {$order->order_number} — {$this->money($order->total, $order->currency)} taken.");
    }

    public function receipt(Request $request, Order $order): Response
    {
        abort_unless($request->user()->can('pos.view'), 403);

        $session = PosSession::query()->whereKey($order->pos_session_id)->firstOrFail();

        $this->authorizeSession($request, $session);

        $order->loadMissing(['items', 'owner:id,name', 'branch:id,name']);

        return Inertia::render('Pos/Receipt', [
            'receipt' => [
                'order_number' => $order->order_number,
                'placed_at' => $order->placed_at?->toDayDateTimeString(),
                'cashier' => $order->owner->name ?? null,
                'branch' => $order->branch->name ?? null,
                'register' => $session->register->name ?? null,
                'customer_name' => $order->customer_name,
                'currency' => $order->currency,
                'subtotal' => (string) $order->subtotal,
                'discount_amount' => (string) $order->discount_amount,
                'tax_amount' => (string) $order->tax_amount,
                'total' => (string) $order->total,
                'refunded' => $order->exception_status === ExceptionStatus::Returned,
            ],
            'company' => [
                'name' => Company::query()->whereKey(app(CompanyContext::class)->idOrFail(self::class))->value('name'),
            ],
            'lines' => $order->items->map(fn ($item): array => [
                'id' => $item->getKey(),
                'sku' => $item->sku,
                'name' => trim((string) $item->product_name.' '.(string) $item->variant_name),
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
            ])->all(),
            'tenders' => Payment::query()
                ->where('order_id', $order->getKey())
                ->orderBy('created_at')
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->getKey(),
                    'method' => (string) $payment->method,
                    'amount' => (string) $payment->amount,
                ])->all(),
        ]);
    }

    public function refund(Request $request, PosSession $session): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sell'), 403);
        $this->authorizeSession($request, $session);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'order_id' => ['required', 'string', Rule::exists('orders', 'id')->where('company_id', $companyId)],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'lines' => ['nullable', 'array'],
            'lines.*.order_item_id' => ['required', 'string', Rule::exists('order_items', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gte:0'],
        ]);

        $sale = Order::query()->findOrFail($data['order_id']);

        $lines = isset($data['lines'])
            ? array_values(array_filter(
                array_map(
                    static fn (array $l): array => ['order_item_id' => $l['order_item_id'], 'quantity' => (string) $l['quantity']],
                    $data['lines']
                ),
                static fn (array $l): bool => bccomp($l['quantity'], '0', 4) === 1
            ))
            : null;

        try {
            $refunded = $this->pos->refund($session, $sale, $data['reason'], $request->user(), $lines);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('till_refund', 'pos', $refunded, $request->user(), $data['reason']);

        return back()->with('success', "Sale {$refunded->order_number} refunded.");
    }

    public function cash(Request $request, PosSession $session): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sell'), 403);
        $this->authorizeSession($request, $session);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['cash_in', 'cash_out', 'drop'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        try {
            $movement = $this->pos->recordCash($session, $data['kind'], (string) $data['amount'], $data['reason'], $request->user());
        } catch (TillRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('till_movement', 'pos', $movement, $request->user());

        return back()->with('success', 'Till movement recorded.');
    }

    public function close(Request $request, PosSession $session): RedirectResponse
    {
        abort_unless($request->user()->can('pos.sell'), 403);
        $this->authorizeSession($request, $session);

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $closed = $this->pos->closeSession($session, (string) $data['counted_cash'], $request->user(), $data['closing_note'] ?? null);
        } catch (TillRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('till_closed', 'pos', $closed, $request->user());

        $variance = Money::of((string) $closed->variance);

        return redirect('/pos')->with(
            'success',
            $variance->isZero()
                ? "Till {$closed->reference} closed and balanced."
                : "Till {$closed->reference} closed with a variance of {$variance->format()}."
        );
    }

    private function authorizeSession(Request $request, PosSession $session): void
    {
        $reachable = PosSession::query()
            ->visibleTo($request->user(), 'pos.view')
            ->whereKey($session->getKey())
            ->exists();

        abort_unless($reachable, 403);
    }

    /** @return array<string, mixed> */
    private function sessionPayload(PosSession $session): array
    {
        $session->loadMissing(['register:id,name,warehouse_id', 'cashier:id,name']);

        $takings = Order::query()
            ->where('pos_session_id', $session->getKey())
            ->selectRaw('count(*) as sales, coalesce(sum(total), 0) as total')
            ->first();

        return [
            'id' => $session->getKey(),
            'reference' => $session->reference,
            'register' => $session->register->name ?? null,
            'cashier' => $session->cashier->name ?? null,
            'status' => $session->status,
            'opening_float' => (string) $session->opening_float,
            'expected_cash' => $this->pos->expectedCash($session)->toDecimal(),
            'counted_cash' => $session->counted_cash === null ? null : (string) $session->counted_cash,
            'variance' => $session->variance === null ? null : (string) $session->variance,
            'sales_count' => (string) ($takings->sales ?? 0),
            'sales_total' => (string) ($takings->total ?? '0'),
            'opened_at' => $session->opened_at?->toDayDateTimeString(),
            'closed_at' => $session->closed_at?->toDayDateTimeString(),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function sellableVariants(PosSession $session): array
    {
        $warehouseId = $session->register?->warehouse_id;

        $onHand = $warehouseId === null ? collect() : Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->pluck('on_hand', 'product_variant_id');

        return ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('status', 'active'))
            ->with('product:id,name')
            ->orderBy('sku')
            ->limit(500)
            ->get()
            ->map(fn (ProductVariant $variant): array => [
                'id' => $variant->getKey(),
                'sku' => (string) $variant->sku,
                'name' => trim(($variant->product->name ?? '').' '.$variant->name),
                'price' => (string) $variant->selling_price,
                'on_hand' => (string) ($onHand[$variant->getKey()] ?? '0'),
            ])
            ->all();
    }

    private function money(mixed $amount, string $currency): string
    {
        return Money::of((string) $amount, $currency)->format();
    }
}
