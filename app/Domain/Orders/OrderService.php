<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Attribution\AttributionService;
use App\Domain\Numbering\DocumentNumberService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Purchasing\CostingService;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly DocumentNumberService $numbers,
        private readonly OrderStateMachine $states,
        private readonly OrderMutabilityPolicy $policy,
        private readonly OrderEventRecorder $events,
        private readonly AttributionService $attribution,
        private readonly CostingService $costing,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Order
    {
        if (($data['lines'] ?? []) === []) {
            throw new InvalidArgumentException('An order needs at least one line.');
        }

        return DB::transaction(function () use ($data, $actor): Order {
            $customer = isset($data['customer_id'])
                ? Customer::query()->whereKey($data['customer_id'])->first()
                : null;

            $order = Order::create([
                'order_number' => $this->numbers->next('order', 'SO'),
                'customer_id' => $customer?->getKey(),
                'branch_id' => $data['branch_id'] ?? null,
                'owner_user_id' => $actor?->getKey(),
                'is_cod' => $data['is_cod'] ?? false,
                'customer_name' => $data['customer_name'] ?? ($customer->name ?? 'Walk-in customer'),
                'customer_phone' => $data['customer_phone'] ?? $customer?->phone,
                'customer_email' => $data['customer_email'] ?? $customer?->email,
                'currency' => $customer->currency ?? Money::DEFAULT_CURRENCY,
                'placed_at' => now(),
            ]);

            foreach ($data['lines'] as $line) {
                $this->snapshotLine($order, $line, $customer, $data['branch_id'] ?? null);
            }

            $this->recalculate($order);

            $lead = isset($data['lead_id']) ? Lead::query()->find($data['lead_id']) : null;

            $this->attribution->freezeOntoOrder($order->refresh(), $lead, $actor);

            if ($lead !== null) {
                $lead->forceFill([
                    'converted_order_id' => $order->getKey(),
                    'converted_customer_id' => $order->customer_id,
                    'converted_at' => now(),
                    'status' => 'won',
                ])->save();
            }

            $this->events->record(
                $order,
                'order.created',
                "Order {$order->order_number} created with ".count($data['lines']).' line(s).',
                null,
                ['total' => (string) $order->fresh()->total],
                $actor,
            );

            return $order->refresh();
        });
    }

    public function recordPayment(
        Order $order,
        string $amount,
        string $method = 'cash',
        ?string $reference = null,
        ?User $actor = null,
    ): Payment {
        return DB::transaction(function () use ($order, $amount, $method, $reference, $actor): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $payment = Payment::create([
                'order_id' => $locked->getKey(),
                'recorded_by' => $actor?->getKey(),
                'method' => $method,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $locked->currency,
                'received_at' => now(),
            ]);

            $paid = Money::of((string) $locked->paid_amount, $locked->currency)
                ->plus(Money::of($amount, $locked->currency));

            $locked->forceFill(['paid_amount' => $paid->toDecimal()])->save();

            $this->events->record(
                $locked,
                'payment.recorded',
                Money::of($amount, $locked->currency)->format()." received by {$method}.",
                ['paid_amount' => (string) $order->paid_amount],
                ['paid_amount' => $paid->toDecimal()],
                $actor,
            );

            $this->settlePaymentStatus($locked->refresh(), $actor);

            return $payment;
        });
    }

    public function refund(Order $order, string $amount, string $method, ?string $reference = null, ?User $actor = null): Payment
    {
        $money = Money::of($amount, $order->currency);

        if ($money->isZero() || $money->isNegative()) {
            throw new InvalidArgumentException('A refund must be a positive amount.');
        }

        $due = $order->refundDue();

        if ($money->greaterThan($due)) {
            throw new InvalidArgumentException(
                "This order is owed {$due->format()} and {$money->format()} was offered. ".
                'Record the goods coming back before refunding more than they are due.'
            );
        }

        return DB::transaction(function () use ($order, $money, $method, $reference, $actor, $due): Payment {
            $settlesEverything = $money->toDecimal() === $due->toDecimal()
                && $order->keptTotal()->isZero();

            if ($settlesEverything && $this->states->canTransition($order, PaymentStatus::Refunded)) {
                $this->states->transition($order, PaymentStatus::Refunded, $actor, 'Refunded in full.');
            }

            return $this->recordPayment(
                $order->refresh(),
                $money->negated()->toDecimal(),
                $method,
                $reference,
                $actor,
            );
        });
    }

    public function recalculate(Order $order): Order
    {
        $currency = $order->currency;
        $subtotal = Money::zero($currency);
        $discount = Money::zero($currency);
        $tax = Money::zero($currency);

        foreach ($order->items()->get() as $item) {
            $subtotal = $subtotal->plus(
                Money::of((string) $item->unit_price, $currency)->times((string) $item->quantity)
            );
            $discount = $discount->plus(Money::of((string) $item->discount_amount, $currency));
            $tax = $tax->plus(Money::of((string) $item->tax_amount, $currency));
        }

        $shipping = Money::of((string) $order->shipping_amount, $currency);
        $total = $subtotal->minus($discount)->plus($tax)->plus($shipping);

        $order->forceFill([
            'subtotal' => $subtotal->toDecimal(),
            'discount_amount' => $discount->toDecimal(),
            'tax_amount' => $tax->toDecimal(),
            'total' => $total->toDecimal(),
        ])->save();

        return $order;
    }

    public function assertEditable(Order $order, string $group): void
    {
        $reason = $this->policy->reasonLocked($order, $group);

        if ($reason !== null) {
            throw new OrderNotEditable($reason);
        }
    }

    /**
     * @param  array{variant_id: string, quantity: string|int}  $line
     */
    private function snapshotLine(Order $order, array $line, ?Customer $customer, ?string $branchId): OrderItem
    {
        $variant = ProductVariant::query()->with('product.taxRate')->findOrFail($line['variant_id']);
        $quantity = (string) $line['quantity'];

        $quote = $this->prices->resolve($variant, $customer, $quantity, $branchId);

        $lineTotal = $quote->unitPrice->times($quantity);
        $tax = $this->taxFor($variant, $lineTotal);

        return OrderItem::create([
            'order_id' => $order->getKey(),
            'product_variant_id' => $variant->getKey(),
            'sku' => $variant->sku,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->name,
            'options' => $variant->options,
            'quantity' => $quantity,
            'unit_price' => $quote->unitPrice->toDecimal(),
            'unit_cost' => $this->costing->costFor($variant)->toDecimal(),
            'unit_cost_source' => $this->costing->costSourceFor($variant),
            'discount_amount' => $quote->discount->times($quantity)->toDecimal(),
            'tax_amount' => $tax->toDecimal(),
            'line_total' => $lineTotal->toDecimal(),
            'price_basis' => $quote->toArray(),
            'weight_grams' => $variant->weight_grams,
        ]);
    }

    private function taxFor(ProductVariant $variant, Money $lineTotal): Money
    {
        $rate = $variant->product?->taxRate;

        if ($rate === null || ! $rate->is_active) {
            return Money::zero($lineTotal->currency);
        }

        if ($rate->is_inclusive) {
            $divisor = bcadd('100', (string) $rate->rate_percent, 6);
            $net = bcdiv(bcmul($lineTotal->toDecimal(), '100', 6), $divisor, 4);

            return $lineTotal->minus(Money::of($net, $lineTotal->currency));
        }

        return $lineTotal->percentage((string) $rate->rate_percent);
    }

    private function settlePaymentStatus(Order $order, ?User $actor): void
    {
        $target = match (true) {
            $order->isFullyPaid() => PaymentStatus::Paid,
            ! Money::of((string) $order->paid_amount, $order->currency)->isZero() => PaymentStatus::PartiallyPaid,
            default => null,
        };

        if ($target === null || $order->payment_status === $target) {
            return;
        }

        if ($this->states->canTransition($order, $target)) {
            $this->states->transition($order, $target, $actor, 'Derived from the amount received.');
        }
    }
}
