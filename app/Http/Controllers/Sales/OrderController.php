<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Attribution\AttributionService;
use App\Domain\Orders\OrderMutabilityPolicy;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Attribution;
use App\Models\Branch;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Support\CompanyContext;
use BackedEnum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderStateMachine $states,
        private readonly OrderMutabilityPolicy $mutability,
        private readonly AttributionService $attribution,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Order::class);

        $term = trim((string) $request->query('q', ''));
        $fulfilment = (string) $request->query('fulfilment', '');
        $payment = (string) $request->query('payment', '');

        $orders = Order::query()
            ->visibleTo($request->user(), 'orders.view')
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('order_number', 'ilike', "%{$term}%")
                    ->orWhere('customer_name', 'ilike', "%{$term}%")
                    ->orWhere('customer_phone', 'ilike', "%{$term}%");
            }))
            ->when($fulfilment !== '', fn ($query) => $query->where('fulfilment_status', $fulfilment))
            ->when($payment !== '', fn ($query) => $query->where('payment_status', $payment))
            ->with('owner:id,name')
            ->orderByDesc('placed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order): array => [
                'id' => $order->getKey(),
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'placed_at' => $order->placed_at?->toDateTimeString(),
                'currency' => $order->currency,
                'total' => (string) $order->total,
                'outstanding' => $order->outstanding()->toDecimal(),
                'payment' => $order->payment_status->value,
                'payment_label' => $order->payment_status->label(),
                'payment_tone' => $order->payment_status->tone(),
                'fulfilment' => $order->fulfilment_status->value,
                'fulfilment_label' => $order->fulfilment_status->label(),
                'fulfilment_tone' => $order->fulfilment_status->tone(),
                'exception' => $order->exception_status->value,
                'exception_label' => $order->exception_status->label(),
                'owner' => $order->owner?->name,
            ]);

        return Inertia::render('Sales/Orders/Index', [
            'orders' => $orders,
            'filters' => ['q' => $term, 'fulfilment' => $fulfilment, 'payment' => $payment],
            'statusOptions' => [
                'fulfilment' => $this->enumOptions(FulfilmentStatus::cases()),
                'payment' => $this->enumOptions(PaymentStatus::cases()),
            ],
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $this->authorize('view', $order);

        $order->loadMissing(['items', 'owner:id,name', 'branch:id,name', 'customer:id,code,name']);

        $user = $request->user();

        return Inertia::render('Sales/Orders/Show', [
            'order' => [
                'id' => $order->getKey(),
                'order_number' => $order->order_number,
                'customer_id' => $order->customer_id,
                'customer_code' => $order->customer?->code,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_email' => $order->customer_email,
                'branch' => $order->branch?->name,
                'owner' => $order->owner?->name,
                'placed_at' => $order->placed_at?->toDayDateTimeString(),
                'currency' => $order->currency,
                'is_cod' => $order->is_cod,
                'subtotal' => (string) $order->subtotal,
                'discount_amount' => (string) $order->discount_amount,
                'tax_amount' => (string) $order->tax_amount,
                'shipping_amount' => (string) $order->shipping_amount,
                'total' => (string) $order->total,
                'paid_amount' => (string) $order->paid_amount,
                'outstanding' => $order->outstanding()->toDecimal(),
                'returned_amount' => (string) $order->returned_amount,
                'refund_due' => $order->refundDue()->toDecimal(),
                'notes' => $order->notes,
                'payment' => $order->payment_status->value,
                'payment_label' => $order->payment_status->label(),
                'payment_tone' => $order->payment_status->tone(),
                'fulfilment' => $order->fulfilment_status->value,
                'fulfilment_label' => $order->fulfilment_status->label(),
                'fulfilment_tone' => $order->fulfilment_status->tone(),
                'exception' => $order->exception_status->value,
                'exception_label' => $order->exception_status->label(),
                'exception_tone' => $order->exception_status->tone(),
            ],
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->getKey(),
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'unit_cost' => (string) $item->unit_cost,
                'unit_cost_source' => $item->unit_cost_source,
                'discount_amount' => (string) $item->discount_amount,
                'tax_amount' => (string) $item->tax_amount,
                'line_total' => (string) $item->line_total,
                'price_basis' => $item->price_basis,
                'margin' => $item->marginAtSale($order->currency)->toDecimal(),
            ])->all(),
            'attribution' => $this->attributionPanel($order),
            'timeline' => OrderEvent::query()
                ->where('order_id', $order->getKey())
                ->with('actor:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (OrderEvent $event): array => [
                    'id' => $event->getKey(),
                    'event' => $event->event,
                    'summary' => $event->summary,
                    'actor' => $event->actor->name ?? 'System',
                    'at' => $event->created_at?->toDayDateTimeString(),
                ])->all(),
            'commissions' => $user?->can('commissions.view') === true
                ? Commission::query()
                    ->visibleTo($user, 'commissions.view')
                    ->where('order_id', $order->getKey())
                    ->with('recipient:id,name')
                    ->get()
                    ->map(fn (Commission $commission): array => [
                        'id' => $commission->getKey(),
                        'recipient' => $commission->recipient->name ?? '—',
                        'role' => $commission->recipient_role,
                        'status' => $commission->status,
                        'amount' => (string) $commission->amount,
                        'is_provisional' => $commission->is_provisional,
                    ])->all()
                : null,
            'invoice' => $this->invoicePanel($order),
            'locks' => $this->mutability->map($order),
            'transitions' => $this->transitionsFor($order),
            'permissions' => [
                'update' => $user?->can('update', $order) ?? false,
                'approve' => $user?->can('approve', $order) ?? false,
                'cancel' => $user?->can('cancel', $order) ?? false,
                'record_payment' => $user?->can('recordPayment', $order) ?? false,
                'issue_invoice' => $user?->can('issueInvoice', $order) ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Order::class);

        return Inertia::render('Sales/Orders/Create', [
            'customers' => Customer::query()
                ->visibleTo($request->user(), 'customers.view')
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(200)
                ->get()
                ->map(fn (Customer $c): array => ['value' => $c->getKey(), 'label' => $c->code.' — '.$c->name])
                ->all(),
            'branches' => Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Branch $b): array => ['value' => $b->getKey(), 'label' => $b->name])
                ->all(),
            'variants' => ProductVariant::query()
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('status', 'active'))
                ->with('product:id,name')
                ->orderBy('sku')
                ->limit(500)
                ->get()
                ->map(fn (ProductVariant $v): array => [
                    'value' => $v->getKey(),
                    'label' => $v->sku.' — '.($v->product->name ?? '').' '.$v->name,
                    'price' => (string) $v->selling_price,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'customer_id' => ['nullable', 'string', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'string', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'is_cod' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'string', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $order = $this->orders->create($data, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect("/orders/{$order->getKey()}")->with('success', "Order {$order->order_number} created.");
    }

    public function transition(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'axis' => ['required', Rule::in(['payment', 'fulfilment', 'exception'])],
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $target = $this->targetFor($data['axis'], $data['status']);

        $this->authorize($this->abilityFor($target), $order);

        try {
            $this->states->transition($order, $target, $request->user(), $data['reason'] ?? null);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order updated.');
    }

    public function recordPayment(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('recordPayment', $order);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'ewallet', 'cod', 'cheque'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->orders->recordPayment($order, (string) $data['amount'], $data['method'], $data['reference'] ?? null, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Payment recorded.');
    }

    public function refund(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('recordPayment', $order);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'ewallet', 'cheque'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->orders->refund($order, (string) $data['amount'], $data['method'], $data['reference'] ?? null, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Refund recorded.');
    }

    /** @return array<string, mixed>|null */
    private function attributionPanel(Order $order): ?array
    {
        $attribution = $this->attribution->attributionFor($order);

        if (! $attribution instanceof Attribution) {
            return null;
        }

        $attribution->loadMissing(['channel:id,name', 'campaign:id,name', 'marketer.user:id,name', 'salesperson:id,name', 'salesTeam:id,name', 'lead:id,reference']);

        return [
            'channel' => $attribution->channel?->name,
            'campaign' => $attribution->campaign?->name,
            'marketer' => $attribution->marketer?->user->name ?? $attribution->marketer?->code,
            'salesperson' => $attribution->salesperson?->name,
            'sales_team' => $attribution->salesTeam?->name,
            'lead' => $attribution->lead?->reference,
            'source' => $attribution->source,
            'medium' => $attribution->medium,
            'captured_at' => $attribution->captured_at?->toDayDateTimeString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function invoicePanel(Order $order): ?array
    {
        $invoice = Invoice::query()
            ->where('order_id', $order->getKey())
            ->whereNot('status', 'void')
            ->first();

        return $invoice === null ? null : [
            'id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'total' => (string) $invoice->total,
            'paid_amount' => (string) $invoice->paid_amount,
            'due_at' => $invoice->due_at?->toDateString(),
        ];
    }

    /** @return array<string, array<int, array{value: string, label: string}>> */
    private function transitionsFor(Order $order): array
    {
        $map = [];

        foreach (['payment', 'fulfilment', 'exception'] as $axis) {
            $map[$axis] = array_map(
                fn (BackedEnum $status): array => [
                    'value' => (string) $status->value,
                    'label' => method_exists($status, 'label') ? $status->label() : (string) $status->value,
                ],
                $this->states->availableTransitions($order, $axis)
            );
        }

        return $map;
    }

    private function targetFor(string $axis, string $status): BackedEnum
    {
        $enum = match ($axis) {
            'payment' => PaymentStatus::class,
            'fulfilment' => FulfilmentStatus::class,
            default => ExceptionStatus::class,
        };

        $target = $enum::tryFrom($status);

        abort_if($target === null, 422, "[{$status}] is not a valid {$axis} status.");

        return $target;
    }

    private function abilityFor(BackedEnum $target): string
    {
        if ($target === FulfilmentStatus::Approved) {
            return 'approve';
        }

        if ($target === ExceptionStatus::Cancelled) {
            return 'cancel';
        }

        return 'update';
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            fn (BackedEnum $case): array => [
                'value' => (string) $case->value,
                'label' => method_exists($case, 'label') ? $case->label() : (string) $case->value,
            ],
            $cases
        );
    }
}
