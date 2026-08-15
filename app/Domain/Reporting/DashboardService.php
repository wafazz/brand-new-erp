<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Models\CommissionRollup;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\SalesRollup;
use App\Models\SalesTarget;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    private const PERMISSION = 'reports.view';

    /** @return array<string, mixed> */
    public function management(User $user, string $period): array
    {
        $sales = $this->salesTotals($user, $period);

        return [
            'variant' => 'management',
            'period' => $period,
            'orders' => $sales['orders'],
            'revenue' => $sales['revenue'],
            'cost' => $sales['cost'],
            'margin' => $sales['margin'],
            'outstanding' => $this->outstanding($user),
            'commission_payable' => $this->commissionTotal($user, $period, 'payable'),
            'top_salespeople' => $this->topBy($user, $period, 'salesperson_user_id'),
            'top_campaigns' => $this->topBy($user, $period, 'campaign_id'),
        ];
    }

    /** @return array<string, mixed> */
    public function sales(User $user, string $period): array
    {
        $sales = $this->salesTotals($user, $period);
        $target = $this->targetFor($user, $period);

        return [
            'variant' => 'sales',
            'period' => $period,
            'orders' => $sales['orders'],
            'revenue' => $sales['revenue'],
            'margin' => $sales['margin'],
            'target' => $target,
            'attainment_percent' => $this->attainment($sales['revenue'], $target),
            'team_breakdown' => $this->topBy($user, $period, 'sales_team_id'),
            'open_leads' => $this->openLeads($user),
        ];
    }

    /** @return array<string, mixed> */
    public function marketing(User $user, string $period): array
    {
        $sales = $this->salesTotals($user, $period);

        return [
            'variant' => 'marketing',
            'period' => $period,
            'revenue' => $sales['revenue'],
            'orders' => $sales['orders'],
            'campaign_breakdown' => $this->topBy($user, $period, 'campaign_id'),
            'channel_breakdown' => $this->topBy($user, $period, 'channel_id'),
            'marketer_breakdown' => $this->topBy($user, $period, 'marketer_id'),
            'open_leads' => $this->openLeads($user),
        ];
    }

    /** @return array<string, mixed> */
    public function marketer(User $user, string $period): array
    {
        $sales = $this->salesTotals($user, $period);

        return [
            'variant' => 'marketer',
            'period' => $period,
            'orders' => $sales['orders'],
            'revenue' => $sales['revenue'],
            'open_leads' => $this->openLeads($user),
            'commission_pending' => $this->commissionTotal($user, $period, 'pending'),
            'commission_paid' => $this->commissionTotal($user, $period, 'paid'),
            'campaign_breakdown' => $this->topBy($user, $period, 'campaign_id'),
        ];
    }

    /** @return array<string, mixed> */
    public function salesperson(User $user, string $period): array
    {
        $sales = $this->salesTotals($user, $period);
        $target = $this->targetFor($user, $period);

        return [
            'variant' => 'salesperson',
            'period' => $period,
            'orders' => $sales['orders'],
            'revenue' => $sales['revenue'],
            'margin' => $sales['margin'],
            'target' => $target,
            'attainment_percent' => $this->attainment($sales['revenue'], $target),
            'commission_pending' => $this->commissionTotal($user, $period, 'pending'),
            'commission_paid' => $this->commissionTotal($user, $period, 'paid'),
            'open_leads' => $this->openLeads($user),
        ];
    }

    /** @return array<string, mixed> */
    public function forRole(User $user, string $variant, string $period): array
    {
        return match ($variant) {
            'management' => $this->management($user, $period),
            'sales' => $this->sales($user, $period),
            'marketing' => $this->marketing($user, $period),
            'marketer' => $this->marketer($user, $period),
            default => $this->salesperson($user, $period),
        };
    }

    /** @return array{orders: int, revenue: string, cost: string, margin: string} */
    private function salesTotals(User $user, string $period): array
    {
        $query = $this->scopedSales($user, $period);

        return [
            'orders' => (int) $query->clone()->sum('orders_count'),
            'revenue' => $this->decimal($query->clone()->sum('revenue')),
            'cost' => $this->decimal($query->clone()->sum('cost')),
            'margin' => $this->decimal($query->clone()->sum('margin')),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function topBy(User $user, string $period, string $column): array
    {
        return $this->scopedSales($user, $period)
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("{$column} as slice, sum(revenue) as revenue, sum(orders_count) as orders")
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn (SalesRollup $row): array => [
                'slice' => (string) $row->getAttribute('slice'),
                'revenue' => $this->decimal($row->getAttribute('revenue')),
                'orders' => (string) $row->getAttribute('orders'),
            ])
            ->all();
    }

    /** @return Builder<SalesRollup> */
    private function scopedSales(User $user, string $period): Builder
    {
        return SalesRollup::query()
            ->visibleTo($user, self::PERMISSION)
            ->whereRaw("to_char(rollup_date, 'YYYY-MM') = ?", [$period]);
    }

    private function commissionTotal(User $user, string $period, string $bucket): string
    {
        return $this->decimal(
            CommissionRollup::query()
                ->visibleTo($user, self::PERMISSION)
                ->where('period', $period)
                ->sum($bucket)
        );
    }

    private function outstanding(User $user): string
    {
        $total = Invoice::query()
            ->visibleTo($user, self::PERMISSION)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->selectRaw('coalesce(sum(total - paid_amount), 0) as outstanding')
            ->value('outstanding');

        return $this->decimal($total);
    }

    private function openLeads(User $user): int
    {
        return Lead::query()
            ->visibleTo($user, self::PERMISSION)
            ->whereNotIn('status', ['won', 'lost'])
            ->count();
    }

    private function targetFor(User $user, string $period): string
    {
        $target = SalesTarget::query()
            ->where('period', $period)
            ->where(fn ($q) => $q->where('user_id', $user->getKey())
                ->orWhereIn('sales_team_id', $user->salesTeamIds()))
            ->sum('target_amount');

        return $this->decimal($target);
    }

    private function attainment(string $revenue, string $target): ?string
    {
        if (bccomp($target, '0', 4) === 0) {
            return null;
        }

        return bcdiv(bcmul($revenue, '100', 4), $target, 2);
    }

    private function decimal(mixed $value): string
    {
        return Money::of($value === null ? '0' : (string) $value)->toDecimal();
    }
}
