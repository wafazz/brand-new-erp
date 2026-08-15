<?php

declare(strict_types=1);

namespace App\Domain\Attribution;

use App\Models\Attribution;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class AttributionReport
{
    public function __construct(
        private readonly AttributionService $attribution,
        private readonly CompanyContext $context,
    ) {}

    private function companyId(): string
    {
        return $this->context->idOrFail(self::class);
    }

    /** @return array<string, mixed>|null */
    public function whereDidThisCustomerComeFrom(Customer $customer): ?array
    {
        return $this->describe($this->attribution->attributionFor($customer));
    }

    /** @return array<string, mixed>|null */
    public function whereDidThisOrderComeFrom(Order $order): ?array
    {
        return $this->describe($this->attribution->attributionFor($order));
    }

    /** @return array<string, mixed>|null */
    public function whoGeneratedTheLead(Order $order): ?array
    {
        $attribution = $this->attribution->attributionFor($order);

        if ($attribution?->marketer_id === null) {
            return null;
        }

        $attribution->loadMissing('marketer.user');

        return [
            'marketer_id' => $attribution->marketer_id,
            'code' => $attribution->marketer?->code,
            'name' => $attribution->marketer?->user?->name,
            'lead_id' => $attribution->lead_id,
        ];
    }

    /** @return array<string, mixed>|null */
    public function whoClosedTheOrder(Order $order): ?array
    {
        $attribution = $this->attribution->attributionFor($order);

        if ($attribution?->salesperson_user_id === null) {
            return null;
        }

        $attribution->loadMissing(['salesperson', 'salesTeam']);

        return [
            'user_id' => $attribution->salesperson_user_id,
            'name' => $attribution->salesperson?->name,
            'sales_team_id' => $attribution->sales_team_id,
            'sales_team' => $attribution->salesTeam?->name,
        ];
    }

    /** @return Collection<int, stdClass> */
    public function whichCampaignGeneratedRevenue(?string $from = null, ?string $to = null): Collection
    {
        return $this->revenueGroupedBy('attributions.campaign_id', $from, $to)
            ->leftJoin('campaigns', 'campaigns.id', '=', 'attributions.campaign_id')
            ->addSelect(['campaigns.code as code', 'campaigns.name as name'])
            ->groupBy('campaigns.code', 'campaigns.name')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function whichMarketerGeneratedRevenue(?string $from = null, ?string $to = null): Collection
    {
        return $this->revenueGroupedBy('attributions.marketer_id', $from, $to)
            ->leftJoin('marketers', 'marketers.id', '=', 'attributions.marketer_id')
            ->leftJoin('users as marketer_users', 'marketer_users.id', '=', 'marketers.user_id')
            ->addSelect(['marketers.code as code', 'marketer_users.name as name'])
            ->groupBy('marketers.code', 'marketer_users.name')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function whichSalespersonGeneratedRevenue(?string $from = null, ?string $to = null): Collection
    {
        return $this->revenueGroupedBy('attributions.salesperson_user_id', $from, $to)
            ->leftJoin('users as seller', 'seller.id', '=', 'attributions.salesperson_user_id')
            ->addSelect(['seller.name as name'])
            ->groupBy('seller.name')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function whichChannelConvertsBest(?string $from = null, ?string $to = null): Collection
    {
        $leads = DB::table('attribution_touches')
            ->select('channel_id', DB::raw('count(distinct subject_id) as lead_count'))
            ->where('company_id', $this->companyId())
            ->where('subject_type', Lead::class)
            ->whereNotNull('channel_id')
            ->groupBy('channel_id');

        return DB::table('channels')
            ->where('channels.company_id', $this->companyId())
            ->leftJoinSub($leads, 'l', 'l.channel_id', '=', 'channels.id')
            ->leftJoin('attributions', 'attributions.channel_id', '=', 'channels.id')
            ->leftJoin('orders', function ($join) use ($from, $to): void {
                $join->on('orders.id', '=', 'attributions.attributable_id')
                    ->where('attributions.attributable_type', '=', Order::class)
                    ->where('orders.exception_status', '!=', 'cancelled')
                    ->where('orders.company_id', '=', $this->companyId());
                $this->applyWindow($join, $from, $to);
            })
            ->select([
                'channels.code',
                'channels.name',
                DB::raw('coalesce(max(l.lead_count), 0) as leads'),
                DB::raw('count(distinct orders.id) as orders'),
                DB::raw('coalesce(sum(orders.total), 0) as revenue'),
            ])
            ->groupBy('channels.code', 'channels.name')
            ->orderByDesc('revenue')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function whatDidThisCampaignCostVersusReturn(?string $from = null, ?string $to = null): Collection
    {
        $spend = DB::table('campaign_costs')
            ->select('campaign_id', DB::raw('sum(amount) as spend'))
            ->where('company_id', $this->companyId())
            ->groupBy('campaign_id');

        $revenue = DB::table('attributions')
            ->where('attributions.company_id', $this->companyId())
            ->join('orders', function ($join) use ($from, $to): void {
                $join->on('orders.id', '=', 'attributions.attributable_id')
                    ->where('attributions.attributable_type', '=', Order::class)
                    ->where('orders.exception_status', '!=', 'cancelled')
                    ->where('orders.company_id', '=', $this->companyId());
                $this->applyWindow($join, $from, $to);
            })
            ->select('attributions.campaign_id', DB::raw('sum(orders.total) as revenue'))
            ->whereNotNull('attributions.campaign_id')
            ->groupBy('attributions.campaign_id');

        return DB::table('campaigns')
            ->where('campaigns.company_id', $this->companyId())
            ->leftJoinSub($spend, 's', 's.campaign_id', '=', 'campaigns.id')
            ->leftJoinSub($revenue, 'r', 'r.campaign_id', '=', 'campaigns.id')
            ->select([
                'campaigns.code',
                'campaigns.name',
                DB::raw('coalesce(s.spend, 0) as spend'),
                DB::raw('coalesce(r.revenue, 0) as revenue'),
                DB::raw('coalesce(r.revenue, 0) - coalesce(s.spend, 0) as net'),
                DB::raw('case when coalesce(s.spend, 0) = 0 then null else round(coalesce(r.revenue, 0) / s.spend, 4) end as roas'),
            ])
            ->orderByDesc('revenue')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function whatIsTheCostPerLeadByCampaign(): Collection
    {
        $spend = DB::table('campaign_costs')
            ->select('campaign_id', DB::raw('sum(amount) as spend'))
            ->where('company_id', $this->companyId())
            ->groupBy('campaign_id');

        $leads = DB::table('attribution_touches')
            ->select('campaign_id', DB::raw('count(distinct subject_id) as lead_count'))
            ->where('company_id', $this->companyId())
            ->where('subject_type', Lead::class)
            ->whereNotNull('campaign_id')
            ->groupBy('campaign_id');

        return DB::table('campaigns')
            ->where('campaigns.company_id', $this->companyId())
            ->leftJoinSub($spend, 's', 's.campaign_id', '=', 'campaigns.id')
            ->leftJoinSub($leads, 'l', 'l.campaign_id', '=', 'campaigns.id')
            ->select([
                'campaigns.code',
                'campaigns.name',
                DB::raw('coalesce(s.spend, 0) as spend'),
                DB::raw('coalesce(l.lead_count, 0) as leads'),
                DB::raw('case when coalesce(l.lead_count, 0) = 0 then null else round(coalesce(s.spend, 0) / l.lead_count, 4) end as cost_per_lead'),
            ])
            ->orderBy('campaigns.code')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function whichTeamHitTarget(string $period, ?string $from = null, ?string $to = null): Collection
    {
        $achieved = DB::table('attributions')
            ->where('attributions.company_id', $this->companyId())
            ->join('orders', function ($join) use ($from, $to): void {
                $join->on('orders.id', '=', 'attributions.attributable_id')
                    ->where('attributions.attributable_type', '=', Order::class)
                    ->where('orders.exception_status', '!=', 'cancelled')
                    ->where('orders.company_id', '=', $this->companyId());
                $this->applyWindow($join, $from, $to);
            })
            ->select('attributions.sales_team_id', DB::raw('sum(orders.total) as achieved'))
            ->whereNotNull('attributions.sales_team_id')
            ->groupBy('attributions.sales_team_id');

        return DB::table('sales_targets')
            ->where('sales_targets.company_id', $this->companyId())
            ->join('sales_teams', 'sales_teams.id', '=', 'sales_targets.sales_team_id')
            ->leftJoinSub($achieved, 'a', 'a.sales_team_id', '=', 'sales_targets.sales_team_id')
            ->where('sales_targets.period', $period)
            ->select([
                'sales_teams.code',
                'sales_teams.name',
                'sales_targets.target_amount as target',
                DB::raw('coalesce(a.achieved, 0) as achieved'),
                DB::raw('case when sales_targets.target_amount = 0 then null else round(coalesce(a.achieved, 0) * 100 / sales_targets.target_amount, 2) end as attainment_percent'),
                DB::raw('coalesce(a.achieved, 0) >= sales_targets.target_amount as hit'),
            ])
            ->orderByDesc('achieved')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function whichBranchGeneratedWhat(?string $from = null, ?string $to = null): Collection
    {
        return $this->revenueGroupedBy('attributions.branch_id', $from, $to)
            ->leftJoin('branches', 'branches.id', '=', 'attributions.branch_id')
            ->addSelect(['branches.code as code', 'branches.name as name'])
            ->groupBy('branches.code', 'branches.name')
            ->get();
    }

    private function revenueGroupedBy(string $column, ?string $from, ?string $to): QueryBuilder
    {
        return DB::table('attributions')
            ->where('attributions.company_id', $this->companyId())
            ->join('orders', function ($join) use ($from, $to): void {
                $join->on('orders.id', '=', 'attributions.attributable_id')
                    ->where('attributions.attributable_type', '=', Order::class)
                    ->where('orders.exception_status', '!=', 'cancelled')
                    ->where('orders.company_id', '=', $this->companyId());
                $this->applyWindow($join, $from, $to);
            })
            ->select([
                DB::raw('count(distinct orders.id) as orders'),
                DB::raw('sum(orders.total) as revenue'),
            ])
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('revenue');
    }

    private function applyWindow(mixed $join, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $join->where('orders.placed_at', '>=', $from);
        }

        if ($to !== null) {
            $join->where('orders.placed_at', '<=', $to);
        }
    }

    /** @return array<string, mixed>|null */
    private function describe(?Attribution $attribution): ?array
    {
        if ($attribution === null) {
            return null;
        }

        $attribution->loadMissing(['channel', 'campaign', 'marketer.user', 'salesperson', 'salesTeam', 'lead']);

        return [
            'channel' => $attribution->channel?->name,
            'campaign' => $attribution->campaign?->name,
            'marketer' => $attribution->marketer?->user?->name,
            'salesperson' => $attribution->salesperson?->name,
            'sales_team' => $attribution->salesTeam?->name,
            'lead_reference' => $attribution->lead?->reference,
            'source' => $attribution->source,
            'medium' => $attribution->medium,
            'branch_id' => $attribution->branch_id,
            'captured_at' => $attribution->captured_at?->toIso8601String(),
            'attributed' => $attribution->channel_id !== null
                || $attribution->campaign_id !== null
                || $attribution->marketer_id !== null
                || $attribution->source !== null,
        ];
    }

    /** @return Builder<Attribution> */
    public function query(): Builder
    {
        return Attribution::query();
    }
}
