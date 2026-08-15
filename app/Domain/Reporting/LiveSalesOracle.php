<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Models\Attribution;
use App\Models\Order;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class LiveSalesOracle
{
    /**
     * @return Collection<string, array<string, mixed>>
     */
    public function forDate(CarbonInterface $date): Collection
    {
        $slices = [];

        $orders = Order::query()
            ->with('items')
            ->whereNot('exception_status', 'cancelled')
            ->whereDate('placed_at', $date->toDateString())
            ->get();

        foreach ($orders as $order) {
            $attribution = Attribution::query()
                ->where('attributable_type', Order::class)
                ->where('attributable_id', $order->getKey())
                ->first();

            $key = $this->keyFor($order, $attribution);

            $slices[$key] ??= [
                'branch_id' => $order->branch_id,
                'salesperson_user_id' => $attribution?->salesperson_user_id,
                'sales_team_id' => $attribution?->sales_team_id,
                'marketer_id' => $attribution?->marketer_id,
                'campaign_id' => $attribution?->campaign_id,
                'channel_id' => $attribution?->channel_id,
                'orders_count' => 0,
                'revenue' => Money::zero(),
                'cost' => Money::zero(),
                'tax' => Money::zero(),
            ];

            $revenue = Money::of((string) $order->subtotal)
                ->minus(Money::of((string) $order->discount_amount));

            $cost = Money::zero();

            foreach ($order->items as $item) {
                $cost = $cost->plus(Money::of((string) $item->unit_cost)->times((string) $item->quantity));
            }

            $slices[$key]['orders_count']++;
            $slices[$key]['revenue'] = $slices[$key]['revenue']->plus($revenue);
            $slices[$key]['cost'] = $slices[$key]['cost']->plus($cost);
            $slices[$key]['tax'] = $slices[$key]['tax']->plus(Money::of((string) $order->tax_amount));
        }

        /** @var array<string, array<string, mixed>> $result */
        $result = [];

        foreach ($slices as $key => $slice) {
            $result[$key] = [
                'branch_id' => $this->nullableString($slice['branch_id']),
                'salesperson_user_id' => $this->nullableString($slice['salesperson_user_id']),
                'sales_team_id' => $this->nullableString($slice['sales_team_id']),
                'marketer_id' => $this->nullableString($slice['marketer_id']),
                'campaign_id' => $this->nullableString($slice['campaign_id']),
                'channel_id' => $this->nullableString($slice['channel_id']),
                'orders_count' => (int) $slice['orders_count'],
                'revenue' => $slice['revenue']->toDecimal(),
                'cost' => $slice['cost']->toDecimal(),
                'margin' => $slice['revenue']->minus($slice['cost'])->toDecimal(),
                'tax' => $slice['tax']->toDecimal(),
            ];
        }

        return collect($result);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function keyFor(Order $order, ?Attribution $attribution): string
    {
        $dimensions = $attribution === null
            ? [null, null, null, null, null]
            : [
                $attribution->salesperson_user_id,
                $attribution->sales_team_id,
                $attribution->marketer_id,
                $attribution->campaign_id,
                $attribution->channel_id,
            ];

        return implode('|', array_map(
            static fn (?string $value): string => $value ?? '-',
            [$order->branch_id, ...$dimensions]
        ));
    }
}
