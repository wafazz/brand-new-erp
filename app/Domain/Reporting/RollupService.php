<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Models\Commission;
use App\Models\CommissionRollup;
use App\Models\Order;
use App\Models\RollupRun;
use App\Models\SalesRollup;
use App\Support\CompanyContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RollupService
{
    public function __construct(private readonly CompanyContext $context) {}

    public function rebuildSales(CarbonInterface $date): int
    {
        $companyId = $this->context->idOrFail(self::class);
        $day = $date->toDateString();

        return DB::transaction(function () use ($companyId, $day): int {
            SalesRollup::query()->whereDate('rollup_date', $day)->delete();

            $rows = DB::table('orders')
                ->leftJoin('attributions', function ($join) use ($companyId): void {
                    $join->on('attributions.attributable_id', '=', 'orders.id')
                        ->where('attributions.attributable_type', '=', Order::class)
                        ->where('attributions.company_id', '=', $companyId);
                })
                ->leftJoin('order_items', function ($join) use ($companyId): void {
                    $join->on('order_items.order_id', '=', 'orders.id')
                        ->where('order_items.company_id', '=', $companyId);
                })
                ->where('orders.company_id', $companyId)
                ->where('orders.exception_status', '!=', 'cancelled')
                ->whereNull('orders.deleted_at')
                ->whereRaw('orders.placed_at::date = ?', [$day])
                ->groupBy(
                    'orders.branch_id',
                    'attributions.salesperson_user_id',
                    'attributions.sales_team_id',
                    'attributions.marketer_id',
                    'attributions.campaign_id',
                    'attributions.channel_id',
                )
                ->selectRaw(<<<'SQL'
                    orders.branch_id,
                    attributions.salesperson_user_id,
                    attributions.sales_team_id,
                    attributions.marketer_id,
                    attributions.campaign_id,
                    attributions.channel_id,
                    count(distinct orders.id) as orders_count,
                    coalesce(sum(distinct_order.revenue), 0) as revenue,
                    coalesce(sum(order_items.unit_cost * order_items.quantity), 0) as cost,
                    coalesce(sum(distinct_order.tax), 0) as tax
                SQL)
                ->joinSub(
                    DB::table('orders')
                        ->where('company_id', $companyId)
                        ->select('id', DB::raw('(subtotal - discount_amount - returned_amount) as revenue'), DB::raw('tax_amount as tax')),
                    'distinct_order',
                    'distinct_order.id',
                    '=',
                    'orders.id'
                )
                ->get();

            $written = 0;

            foreach ($rows as $row) {
                $revenue = (string) $row->revenue;
                $cost = (string) $row->cost;

                SalesRollup::create([
                    'rollup_date' => $day,
                    'branch_id' => $row->branch_id,
                    'salesperson_user_id' => $row->salesperson_user_id,
                    'sales_team_id' => $row->sales_team_id,
                    'marketer_id' => $row->marketer_id,
                    'campaign_id' => $row->campaign_id,
                    'channel_id' => $row->channel_id,
                    'orders_count' => (int) $row->orders_count,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'margin' => bcsub($revenue, $cost, 4),
                    'tax' => (string) $row->tax,
                ]);

                $written++;
            }

            RollupRun::create([
                'kind' => 'sales',
                'scope_key' => $day,
                'rows_written' => $written,
                'ran_at' => now(),
            ]);

            return $written;
        });
    }

    public function rebuildCommission(string $period): int
    {
        $companyId = $this->context->idOrFail(self::class);

        return DB::transaction(function () use ($companyId, $period): int {
            CommissionRollup::query()->where('period', $period)->delete();

            $rows = DB::table('commissions')
                ->where('company_id', $companyId)
                ->where('period', $period)
                ->groupBy('recipient_user_id', 'recipient_role')
                ->selectRaw(<<<'SQL'
                    recipient_user_id,
                    recipient_role,
                    coalesce(sum(amount) filter (where status = 'pending'), 0) as pending,
                    coalesce(sum(amount) filter (where status = 'approved'), 0) as approved,
                    coalesce(sum(amount) filter (where status = 'payable'), 0) as payable,
                    coalesce(sum(amount) filter (where status = 'paid'), 0) as paid,
                    coalesce(sum(amount) filter (where type = 'reversal'), 0) as reversed,
                    coalesce(sum(amount), 0) as net
                SQL)
                ->get();

            $written = 0;

            foreach ($rows as $row) {
                CommissionRollup::create([
                    'period' => $period,
                    'recipient_user_id' => $row->recipient_user_id,
                    'recipient_role' => $row->recipient_role,
                    'pending' => (string) $row->pending,
                    'approved' => (string) $row->approved,
                    'payable' => (string) $row->payable,
                    'paid' => (string) $row->paid,
                    'reversed' => (string) $row->reversed,
                    'net' => (string) $row->net,
                ]);

                $written++;
            }

            RollupRun::create([
                'kind' => 'commission',
                'scope_key' => $period,
                'rows_written' => $written,
                'ran_at' => now(),
            ]);

            return $written;
        });
    }

    public function liveCommissionNet(string $period): string
    {
        $companyId = $this->context->idOrFail(self::class);

        return (string) Commission::query()
            ->where('company_id', $companyId)
            ->where('period', $period)
            ->sum('amount');
    }
}
