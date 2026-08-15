<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

use App\Domain\Finance\InvoiceService;
use App\Domain\Numbering\DocumentNumberService;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubscriptionService
{
    public const INTERVALS = ['weekly', 'monthly', 'quarterly', 'yearly'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InvoiceService $invoices,
    ) {}

    public function advance(CarbonImmutable $from, string $interval): CarbonImmutable
    {
        return match ($interval) {
            'weekly' => $from->addWeek(),
            'quarterly' => $from->addMonthsNoOverflow(3),
            'yearly' => $from->addYearNoOverflow(),
            default => $from->addMonthNoOverflow(),
        };
    }

    public function start(
        Customer $customer,
        SubscriptionPlan $plan,
        CarbonImmutable $startsOn,
        string $quantity = '1',
        ?User $actor = null,
    ): Subscription {
        if (! $plan->is_active) {
            throw new SubscriptionRefused("{$plan->name} is no longer offered.");
        }

        if (bccomp($quantity, '0', 4) !== 1) {
            throw new SubscriptionRefused('A subscription needs a quantity of at least one.');
        }

        return DB::transaction(fn (): Subscription => Subscription::create([
            'customer_id' => $customer->getKey(),
            'subscription_plan_id' => $plan->getKey(),
            'owner_user_id' => $actor?->getKey(),
            'reference' => $this->numbers->next('subscription', 'SUB'),
            'status' => 'active',
            'quantity' => $quantity,
            'unit_price' => (string) $plan->price,
            'currency' => (string) $plan->currency,
            'starts_on' => $startsOn,
            'next_invoice_on' => $startsOn,
        ]));
    }

    public function pause(Subscription $subscription, User $actor): Subscription
    {
        if ($subscription->status !== 'active') {
            throw new SubscriptionRefused("This subscription is {$subscription->status} and cannot be paused.");
        }

        $subscription->forceFill(['status' => 'paused'])->save();

        return $subscription->refresh();
    }

    public function resume(Subscription $subscription, User $actor): Subscription
    {
        if ($subscription->status !== 'paused') {
            throw new SubscriptionRefused("This subscription is {$subscription->status} and cannot be resumed.");
        }

        $next = $subscription->next_invoice_on;
        $today = now()->toImmutable()->startOfDay();

        $subscription->forceFill([
            'status' => 'active',
            'next_invoice_on' => $next !== null && $next->lessThan($today) ? $today : $next,
        ])->save();

        return $subscription->refresh();
    }

    public function cancel(Subscription $subscription, string $reason, User $actor): Subscription
    {
        if (in_array($subscription->status, ['cancelled', 'ended'], true)) {
            throw new SubscriptionRefused("This subscription is already {$subscription->status}.");
        }

        if (trim($reason) === '') {
            throw new SubscriptionRefused('A cancellation needs a reason.');
        }

        $subscription->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => trim($reason),
            'ends_on' => now()->toImmutable()->startOfDay(),
        ])->save();

        return $subscription->refresh();
    }

    public function billDue(?CarbonImmutable $upTo = null, ?User $actor = null): BillingRun
    {
        $deadline = ($upTo ?? now()->toImmutable())->startOfDay();
        $run = new BillingRun;

        $due = Subscription::query()
            ->where('status', 'active')
            ->where('next_invoice_on', '<=', $deadline)
            ->orderBy('next_invoice_on')
            ->get();

        foreach ($due as $subscription) {
            try {
                $this->billOnce($subscription, $actor) ? $run->billed++ : $run->alreadyBilled++;
            } catch (Throwable $exception) {
                $run->skipped[] = "{$subscription->reference}: {$exception->getMessage()}";
            }
        }

        return $run;
    }

    public function billOnce(Subscription $subscription, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($subscription, $actor): bool {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            if (! $locked->isBillable()) {
                throw new SubscriptionRefused("{$locked->reference} is {$locked->status} and is not billed.");
            }

            $period = $locked->next_invoice_on;

            if ($period === null) {
                throw new SubscriptionRefused("{$locked->reference} has no next billing date.");
            }

            if ($locked->ends_on !== null && $period->greaterThan($locked->ends_on)) {
                $locked->forceFill(['status' => 'ended'])->save();

                return false;
            }

            $plan = $locked->plan;
            $variant = $plan?->variant;

            if ($variant === null) {
                throw new SubscriptionRefused("{$locked->reference} has no product to bill for.");
            }

            $customer = $locked->customer;
            $amount = $locked->chargeAmount();

            $alreadyBilled = Order::query()
                ->where('subscription_id', $locked->getKey())
                ->whereDate('billing_period', $period)
                ->exists();

            if ($alreadyBilled) {
                $this->moveToNextPeriod($locked);

                return false;
            }

            $order = Order::create([
                'order_number' => $this->numbers->next('order', 'SO'),
                'customer_id' => $locked->customer_id,
                'subscription_id' => $locked->getKey(),
                'billing_period' => $period,
                'owner_user_id' => $locked->owner_user_id,
                'customer_name' => $customer->name ?? 'Subscriber',
                'customer_email' => $customer?->email,
                'currency' => $locked->currency,
                'placed_at' => $period,
            ]);

            OrderItem::create([
                'order_id' => $order->getKey(),
                'product_variant_id' => $variant->getKey(),
                'sku' => $variant->sku,
                'product_name' => $variant->product->name ?? $plan->name,
                'variant_name' => $variant->name,
                'quantity' => (string) $locked->quantity,
                'unit_price' => (string) $locked->unit_price,
                'line_total' => $amount->toDecimal(),
            ]);

            $order->forceFill([
                'subtotal' => $amount->toDecimal(),
                'total' => $amount->toDecimal(),
            ])->save();

            $this->invoices->issueFromOrder($order->refresh(), 30, $actor);

            $this->moveToNextPeriod($locked);

            return true;
        });
    }

    private function moveToNextPeriod(Subscription $subscription): void
    {
        $period = $subscription->next_invoice_on;

        if ($period === null) {
            return;
        }

        $next = $this->advance($period, (string) $subscription->plan?->interval);

        $subscription->forceFill([
            'next_invoice_on' => $next,
            'status' => $subscription->ends_on !== null && $next->greaterThan($subscription->ends_on)
                ? 'ended'
                : $subscription->status,
        ])->save();
    }
}
