<?php

declare(strict_types=1);

namespace App\Http\Controllers\Subscriptions;

use App\Domain\Finance\InvoiceService;
use App\Domain\Payments\BillplzClient;
use App\Domain\Subscriptions\SubscriptionRefused;
use App\Domain\Subscriptions\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('customers.view'), 403);

        $status = (string) $request->query('status', '');

        $rows = Subscription::query()
            ->visibleTo($request->user(), 'customers.view')
            ->when(in_array($status, ['active', 'paused', 'cancelled', 'ended'], true), fn ($q) => $q->where('status', $status))
            ->with(['customer:id,code,name', 'plan:id,name,interval', 'owner:id,name'])
            ->orderBy('next_invoice_on')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Subscription $row): array => [
                'id' => $row->getKey(),
                'reference' => $row->reference,
                'customer' => $row->customer->name ?? null,
                'plan' => $row->plan->name ?? null,
                'interval' => $row->plan->interval ?? null,
                'status' => $row->status,
                'quantity' => (string) $row->quantity,
                'unit_price' => (string) $row->unit_price,
                'charge' => $row->chargeAmount()->toDecimal(),
                'currency' => $row->currency,
                'next_invoice_on' => $row->next_invoice_on?->toDateString(),
                'overdue' => $row->status === 'active' && ($row->next_invoice_on?->isPast() ?? false),
                'owner' => $row->owner->name ?? null,
            ]);

        $monthly = Money::zero();

        foreach (Subscription::query()->visibleTo($request->user(), 'customers.view')->where('status', 'active')->with('plan')->get() as $row) {
            $monthly = $monthly->plus($this->monthlyValue($row));
        }

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $rows,
            'filters' => ['status' => $status],
            'monthlyValue' => $monthly->toDecimal(),
            'plans' => SubscriptionPlan::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (SubscriptionPlan $p): array => [
                    'value' => $p->getKey(),
                    'label' => $p->name.' — '.$p->interval.' '.$p->currency.' '.$p->price,
                ])->all(),
            'customers' => Customer::query()->where('status', 'active')->orderBy('name')->limit(200)->get()
                ->map(fn (Customer $c): array => ['value' => $c->getKey(), 'label' => $c->code.' — '.$c->name])->all(),
            'can' => [
                'manage' => $request->user()->can('customers.update'),
                'configure' => $request->user()->can('products.update'),
            ],
        ]);
    }

    public function show(Request $request, Subscription $subscription): Response
    {
        abort_unless($request->user()->can('customers.view'), 403);

        $reachable = Subscription::query()
            ->visibleTo($request->user(), 'customers.view')
            ->whereKey($subscription->getKey())
            ->exists();

        abort_unless($reachable, 403);

        $subscription->loadMissing(['customer:id,code,name', 'plan:id,name,interval', 'owner:id,name']);

        return Inertia::render('Subscriptions/Show', [
            'subscription' => [
                'id' => $subscription->getKey(),
                'reference' => $subscription->reference,
                'customer_id' => $subscription->customer_id,
                'customer' => $subscription->customer->name ?? null,
                'plan' => $subscription->plan->name ?? null,
                'interval' => $subscription->plan->interval ?? null,
                'status' => $subscription->status,
                'quantity' => (string) $subscription->quantity,
                'unit_price' => (string) $subscription->unit_price,
                'charge' => $subscription->chargeAmount()->toDecimal(),
                'currency' => $subscription->currency,
                'starts_on' => $subscription->starts_on?->toDateString(),
                'next_invoice_on' => $subscription->next_invoice_on?->toDateString(),
                'ends_on' => $subscription->ends_on?->toDateString(),
                'cancel_reason' => $subscription->cancel_reason,
                'collect_online' => $subscription->collect_online,
            ],
            'charges' => Order::query()
                ->where('subscription_id', $subscription->getKey())
                ->orderByDesc('billing_period')
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'period' => $order->billing_period?->toDateString(),
                    'total' => (string) $order->total,
                    'paid' => (string) $order->paid_amount,
                    'currency' => $order->currency,
                ])->all(),
            'can' => [
                'manage' => $request->user()->can('customers.update'),
                'collect_online' => $request->user()->can('payments.create')
                    && app(BillplzClient::class)->configured(),
            ],
            'paymentLinks' => Invoice::query()
                ->whereHas('order', fn ($query) => $query->where('subscription_id', $subscription->getKey()))
                ->with(['paymentIntents' => fn ($query) => $query->where('status', 'pending')->latest()])
                ->orderByDesc('issued_at')
                ->get()
                ->map(fn (Invoice $invoice): array => [
                    'invoice_id' => $invoice->getKey(),
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'outstanding' => $this->invoices->outstanding($invoice)->toDecimal(),
                    'pay_url' => $invoice->paymentIntents->first()?->pay_url,
                ])->all(),
        ]);
    }

    public function collectOnline(Request $request, Subscription $subscription): RedirectResponse
    {
        abort_unless($request->user()->can('payments.create'), 403);

        $data = $request->validate(['collect_online' => ['required', 'boolean']]);

        $subscription->forceFill(['collect_online' => $data['collect_online']])->save();

        $this->recorder->record(
            $data['collect_online'] ? 'collect_online_enabled' : 'collect_online_disabled',
            'subscriptions',
            $subscription,
            $request->user(),
        );

        return back()->with('success', $data['collect_online']
            ? 'Future invoices for this subscription will carry an online payment link.'
            : 'Online collection switched off for this subscription.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('customers.update'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'customer_id' => ['required', 'string', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'subscription_plan_id' => ['required', 'string', Rule::exists('subscription_plans', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'starts_on' => ['required', 'date'],
            'collect_online' => ['nullable', 'boolean'],
        ]);

        try {
            $subscription = $this->subscriptions->start(
                Customer::query()->findOrFail($data['customer_id']),
                SubscriptionPlan::query()->findOrFail($data['subscription_plan_id']),
                now()->parse($data['starts_on'])->toImmutable()->startOfDay(),
                (string) $data['quantity'],
                $request->user(),
            );
        } catch (SubscriptionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if (($data['collect_online'] ?? false) && $request->user()->can('payments.create')) {
            $subscription->forceFill(['collect_online' => true])->save();
        }

        $this->recorder->record('created', 'subscriptions', $subscription, $request->user());

        return redirect("/subscriptions/{$subscription->getKey()}")
            ->with('success', "{$subscription->reference} started.");
    }

    public function storePlan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('products.update'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'product_variant_id' => ['required', 'string', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'code' => ['required', 'string', 'max:40', Rule::unique('subscription_plans', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:120'],
            'interval' => ['required', Rule::in(SubscriptionService::INTERVALS)],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $plan = SubscriptionPlan::create($data);

        $this->recorder->record('plan_created', 'subscriptions', $plan, $request->user());

        return back()->with('success', "Plan {$plan->name} added.");
    }

    public function plans(Request $request): Response
    {
        abort_unless($request->user()->can('products.update'), 403);

        return Inertia::render('Subscriptions/Plans', [
            'plans' => SubscriptionPlan::query()
                ->with('variant:id,sku,name')
                ->orderBy('name')
                ->get()
                ->map(fn (SubscriptionPlan $plan): array => [
                    'id' => $plan->getKey(),
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'interval' => $plan->interval,
                    'price' => (string) $plan->price,
                    'currency' => $plan->currency,
                    'sku' => $plan->variant->sku ?? null,
                    'is_active' => $plan->is_active,
                    'subscribers' => Subscription::query()->where('subscription_plan_id', $plan->getKey())->where('status', 'active')->count(),
                ])->all(),
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
            'intervals' => array_map(
                static fn (string $i): array => ['value' => $i, 'label' => ucfirst($i)],
                SubscriptionService::INTERVALS
            ),
        ]);
    }

    public function pause(Request $request, Subscription $subscription): RedirectResponse
    {
        return $this->transition($request, $subscription, 'pause');
    }

    public function resume(Request $request, Subscription $subscription): RedirectResponse
    {
        return $this->transition($request, $subscription, 'resume');
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        abort_unless($request->user()->can('customers.update'), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        try {
            $cancelled = $this->subscriptions->cancel($subscription, $data['reason'], $request->user());
        } catch (SubscriptionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('cancelled', 'subscriptions', $cancelled, $request->user());

        return back()->with('success', "{$cancelled->reference} cancelled.");
    }

    private function transition(Request $request, Subscription $subscription, string $action): RedirectResponse
    {
        abort_unless($request->user()->can('customers.update'), 403);

        try {
            $updated = $action === 'pause'
                ? $this->subscriptions->pause($subscription, $request->user())
                : $this->subscriptions->resume($subscription, $request->user());
        } catch (SubscriptionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record($action.'d', 'subscriptions', $updated, $request->user());

        return back()->with('success', "{$updated->reference} {$action}d.");
    }

    private function monthlyValue(Subscription $subscription): Money
    {
        $charge = $subscription->chargeAmount();

        return match ($subscription->plan?->interval) {
            'weekly' => $charge->times('4.3333'),
            'quarterly' => Money::of(bcdiv($charge->toDecimal(), '3', 4), $subscription->currency),
            'yearly' => Money::of(bcdiv($charge->toDecimal(), '12', 4), $subscription->currency),
            default => $charge,
        };
    }
}
