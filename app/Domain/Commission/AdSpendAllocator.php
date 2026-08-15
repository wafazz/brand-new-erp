<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Models\Attribution;
use App\Models\CampaignCost;
use App\Models\Order;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class AdSpendAllocator
{
    public function __construct(private readonly CompanyContext $context) {}

    /** @return array{amount: Money, basis: string} */
    public function forOrder(Order $order, string $rule, string $period): array
    {
        $currency = $order->currency;

        if ($rule === 'excluded') {
            return ['amount' => Money::zero($currency), 'basis' => 'This plan excludes ad spend from the margin.'];
        }

        $attribution = Attribution::query()
            ->where('attributable_type', Order::class)
            ->where('attributable_id', $order->getKey())
            ->first();

        if ($attribution?->campaign_id === null) {
            return ['amount' => Money::zero($currency), 'basis' => 'This order is not attributed to a campaign, so it carries no ad spend.'];
        }

        $spend = Money::of(
            (string) CampaignCost::query()
                ->where('campaign_id', $attribution->campaign_id)
                ->where('period', $period)
                ->sum('amount'),
            $currency
        );

        if ($spend->isZero()) {
            return ['amount' => Money::zero($currency), 'basis' => "No ad spend was recorded for this campaign in {$period}."];
        }

        $siblings = $this->siblingOrders($attribution->campaign_id, $period);

        if ($siblings->count === 0) {
            return ['amount' => Money::zero($currency), 'basis' => 'No eligible orders share this campaign period.'];
        }

        if ($rule === 'equal_per_order') {
            $share = bcdiv($spend->toDecimal(), (string) $siblings->count, 4);

            return [
                'amount' => Money::of($share, $currency),
                'basis' => "{$spend->format()} spread equally across {$siblings->count} order(s) in {$period}.",
            ];
        }

        $denominator = (string) $siblings->total;

        if (bccomp($denominator, '0', 4) === 0) {
            return ['amount' => Money::zero($currency), 'basis' => 'Campaign orders in this period total zero, so no share can be computed.'];
        }

        $weight = bcdiv((string) $order->total, $denominator, 8);
        $share = bcmul($spend->toDecimal(), $weight, 4);

        return [
            'amount' => Money::of($share, $currency),
            'basis' => "{$spend->format()} apportioned by order value: this order is ".
                $this->percent($weight)."% of {$siblings->count} campaign order(s) in {$period}.",
        ];
    }

    private function siblingOrders(string $campaignId, string $period): object
    {
        $companyId = $this->context->idOrFail(self::class);

        $row = DB::table('attributions')
            ->join('orders', function ($join) use ($companyId): void {
                $join->on('orders.id', '=', 'attributions.attributable_id')
                    ->where('attributions.attributable_type', '=', Order::class)
                    ->where('orders.exception_status', '!=', 'cancelled')
                    ->where('orders.company_id', '=', $companyId);
            })
            ->where('attributions.company_id', $companyId)
            ->where('attributions.campaign_id', $campaignId)
            ->whereRaw("to_char(orders.placed_at, 'YYYY-MM') = ?", [$period])
            ->selectRaw('count(distinct orders.id) as count, coalesce(sum(orders.total), 0) as total')
            ->first();

        return (object) ['count' => (int) ($row->count ?? 0), 'total' => $row->total ?? '0'];
    }

    private function percent(string $weight): string
    {
        return rtrim(rtrim(bcmul($weight, '100', 2), '0'), '.') ?: '0';
    }
}
