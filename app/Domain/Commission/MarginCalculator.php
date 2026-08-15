<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Models\CommissionPlan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;

class MarginCalculator
{
    public function __construct(private readonly AdSpendAllocator $ads) {}

    public function forOrder(Order $order, CommissionPlan $plan, string $period): MarginBreakdown
    {
        $currency = $order->currency;

        $sales = Money::of((string) $order->subtotal, $currency)
            ->minus(Money::of((string) $order->discount_amount, $currency))
            ->plus(Money::of((string) $order->shipping_amount, $currency));

        $cost = Money::zero($currency);

        foreach ($order->items()->get() as $item) {
            $cost = $cost->plus($this->lineCost($item, $currency));
        }

        $shipping = Money::of((string) $order->shipping_cost, $currency);
        $fees = Money::of((string) $order->payment_fee, $currency);

        $allocation = $this->ads->forOrder($order, $plan->ad_spend_allocation, $period);
        $adSpend = $allocation['amount'];

        $margin = $sales->minus($cost)->minus($shipping)->minus($fees)->minus($adSpend);

        $components = [
            new MarginComponent('sales', 'Sales', $sales, '+', 'Order subtotal less discount, plus shipping charged.'),
            new MarginComponent('cost', 'Cost', $cost, '-', 'Unit cost frozen on each line at the time of sale.'),
            new MarginComponent('shipping', 'Shipping', $shipping, '-', 'Fulfilment cost recorded against the order.'),
            new MarginComponent('fees', 'Fees', $fees, '-', 'Payment gateway or COD fee recorded against the order.'),
            new MarginComponent('ads', 'Ads', $adSpend, '-', $allocation['basis']),
        ];

        return new MarginBreakdown(
            sales: $sales,
            cost: $cost,
            shipping: $shipping,
            fees: $fees,
            adSpend: $adSpend,
            margin: $margin,
            isProvisional: ! $order->costs_reconciled,
            components: $components,
        );
    }

    public function orderValue(Order $order): Money
    {
        return Money::of((string) $order->subtotal, $order->currency)
            ->minus(Money::of((string) $order->discount_amount, $order->currency));
    }

    private function lineCost(OrderItem $item, string $currency): Money
    {
        return Money::of((string) $item->unit_cost, $currency)->times((string) $item->quantity);
    }
}
