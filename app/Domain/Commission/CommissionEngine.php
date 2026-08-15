<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Models\Attribution;
use App\Models\Commission;
use App\Models\CommissionEvent;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Models\CommissionSource;
use App\Models\Marketer;
use App\Models\Order;
use App\Models\SalesTeam;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommissionEngine
{
    public function __construct(private readonly MarginCalculator $margins) {}

    /** @return Collection<int, Commission> */
    public function accrueForOrder(Order $order, ?User $actor = null): Collection
    {
        $period = $order->placed_at?->format('Y-m') ?? now()->format('Y-m');
        $attribution = $this->attributionFor($order);

        $accrued = collect();

        foreach (CommissionPlan::query()->where('is_active', true)->get() as $plan) {
            $recipient = $this->recipientFor($plan, $attribution, $order);

            if ($recipient === null) {
                continue;
            }

            $version = $this->ruleVersionFor($plan, $order);

            if ($version === null) {
                continue;
            }

            $accrued->push($this->accrueOne($order, $plan, $version, $recipient, $period, $actor));
        }

        return $accrued;
    }

    public function reverse(Commission $commission, string $reason, ?User $actor = null): Commission
    {
        if ($commission->type === 'reversal') {
            throw new CommissionNotPermitted('A reversal cannot itself be reversed.');
        }

        if ($commission->status === 'reversed') {
            throw new CommissionNotPermitted('This commission has already been reversed.');
        }

        return DB::transaction(function () use ($commission, $reason, $actor): Commission {
            $original = Commission::query()->lockForUpdate()->findOrFail($commission->getKey());

            $contra = Commission::create([
                'order_id' => $original->order_id,
                'order_item_id' => $original->order_item_id,
                'recipient_user_id' => $original->recipient_user_id,
                'recipient_role' => $original->recipient_role,
                'commission_plan_id' => $original->commission_plan_id,
                'commission_rule_id' => $original->commission_rule_id,
                'commission_rule_version_id' => $original->commission_rule_version_id,
                'reverses_commission_id' => $original->getKey(),
                'type' => 'reversal',
                'status' => 'approved',
                'is_provisional' => false,
                'period' => now()->format('Y-m'),
                'currency' => $original->currency,
                'basis_amount' => (string) $original->basis_amount,
                'rate_type' => $original->rate_type,
                'rate_applied' => (string) $original->rate_applied,
                'amount' => Money::of((string) $original->amount, $original->currency)->negated()->toDecimal(),
                'calc_inputs' => [
                    'reversal_of' => $original->getKey(),
                    'reason' => $reason,
                    'original' => $original->calc_inputs,
                ],
            ]);

            foreach ($original->sources()->get() as $source) {
                CommissionSource::create([
                    'commission_id' => $contra->getKey(),
                    'order_id' => $source->order_id,
                    'order_item_id' => $source->order_item_id,
                    'contribution' => Money::of((string) $source->contribution, $original->currency)->negated()->toDecimal(),
                ]);
            }

            $original->forceFill(['status' => 'reversed'])->save();

            $this->write($original, $actor, 'reversed', "Reversed by a contra entry. Reason: {$reason}");
            $this->write($contra, $actor, 'created', "Contra entry reversing {$original->getKey()}. Reason: {$reason}");

            return $contra->refresh();
        });
    }

    public function finalise(Commission $commission, ?User $actor = null): Commission
    {
        if (! $commission->is_provisional) {
            return $commission;
        }

        $order = $commission->order;

        if ($order === null || ! $order->costs_reconciled) {
            throw new CommissionNotPermitted(
                'This order\'s costs are not reconciled yet, so the commission cannot be finalised.'
            );
        }

        return DB::transaction(function () use ($commission, $order, $actor): Commission {
            $locked = Commission::query()->lockForUpdate()->findOrFail($commission->getKey());

            $plan = $locked->plan;
            $version = $locked->ruleVersion;
            $breakdown = $this->margins->forOrder($order, $plan, $locked->period);
            $basis = $this->basisFor($plan, $order, $breakdown);
            $amount = $this->amountFor($version, $basis, $order);

            $before = ['amount' => (string) $locked->amount, 'basis_amount' => (string) $locked->basis_amount];

            $locked->forceFill([
                'basis_amount' => $basis->toDecimal(),
                'amount' => $amount->toDecimal(),
                'calc_inputs' => $this->inputs($plan, $version, $breakdown, $basis, $amount, false),
                'is_provisional' => false,
                'finalised_at' => now(),
            ])->save();

            $this->write(
                $locked,
                $actor,
                'finalised',
                "Restated against reconciled costs: {$before['amount']} became {$amount->toDecimal()}.",
                $before,
                ['amount' => $amount->toDecimal(), 'basis_amount' => $basis->toDecimal()],
            );

            return $locked->refresh();
        });
    }

    public function explain(Commission $commission): string
    {
        $inputs = $commission->calc_inputs;
        $money = Money::of((string) $commission->amount, $commission->currency);
        $basis = Money::of((string) $commission->basis_amount, $commission->currency);

        $commission->loadMissing(['recipient', 'ruleVersion.rule', 'order']);

        $rate = $commission->rate_type === 'percent'
            ? rtrim(rtrim((string) $commission->rate_applied, '0'), '.').'% of'
            : 'a fixed amount against';

        $line = "Commission {$money->format()} — Recipient: {$commission->recipient?->name} ".
            "({$commission->recipient_role}) · Rule: \"{$commission->ruleVersion?->rule?->name}\" ".
            "v{$commission->ruleVersion?->version} (effective {$commission->ruleVersion?->valid_from?->toDateString()}) · ".
            "{$rate} {$basis->format()}";

        if (isset($inputs['margin']['explanation'])) {
            $line .= ' · '.$inputs['margin']['explanation'];
        }

        if ($commission->order !== null) {
            $line .= " · Order {$commission->order->order_number}";
        }

        return $line.($commission->is_provisional ? ' · Provisional — final at period close' : '');
    }

    private function accrueOne(
        Order $order,
        CommissionPlan $plan,
        CommissionRuleVersion $version,
        User $recipient,
        string $period,
        ?User $actor,
    ): Commission {
        return DB::transaction(function () use ($order, $plan, $version, $recipient, $period, $actor): Commission {
            $existing = Commission::query()
                ->where('order_id', $order->getKey())
                ->whereNull('order_item_id')
                ->where('recipient_user_id', $recipient->getKey())
                ->where('commission_plan_id', $plan->getKey())
                ->where('type', 'direct')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status !== 'pending') {
                return $existing;
            }

            $breakdown = $this->margins->forOrder($order, $plan, $period);
            $basis = $this->basisFor($plan, $order, $breakdown);
            $amount = $this->amountFor($version, $basis, $order);
            $inputs = $this->inputs($plan, $version, $breakdown, $basis, $amount, $breakdown->isProvisional);

            $attributes = [
                'recipient_role' => $plan->recipient_role,
                'commission_rule_id' => $version->commission_rule_id,
                'commission_rule_version_id' => $version->getKey(),
                'status' => 'pending',
                'is_provisional' => $breakdown->isProvisional,
                'period' => $period,
                'currency' => $order->currency,
                'basis_amount' => $basis->toDecimal(),
                'rate_type' => $version->rate_type,
                'rate_applied' => (string) $version->rate_value,
                'amount' => $amount->toDecimal(),
                'calc_inputs' => $inputs,
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();
                $this->write($existing, $actor, 'recalculated', "Recalculated to {$amount->format()}.");

                return $existing->refresh();
            }

            $commission = Commission::create([
                'order_id' => $order->getKey(),
                'recipient_user_id' => $recipient->getKey(),
                'commission_plan_id' => $plan->getKey(),
                'type' => 'direct',
                ...$attributes,
            ]);

            CommissionSource::create([
                'commission_id' => $commission->getKey(),
                'order_id' => $order->getKey(),
                'contribution' => $basis->toDecimal(),
            ]);

            $this->write($commission, $actor, 'created', "Accrued {$amount->format()} on {$plan->name}.");

            return $commission->refresh();
        });
    }

    private function basisFor(CommissionPlan $plan, Order $order, MarginBreakdown $breakdown): Money
    {
        return match ($plan->strategy) {
            'percentage_of_margin' => $breakdown->margin,
            'percentage_of_value' => $this->margins->orderValue($order),
            default => $this->margins->orderValue($order),
        };
    }

    private function amountFor(CommissionRuleVersion $version, Money $basis, Order $order): Money
    {
        $rate = (string) $version->rate_value;

        if ($version->rate_type === 'fixed') {
            return Money::of($rate, $order->currency);
        }

        if ($basis->isNegative()) {
            return Money::zero($order->currency);
        }

        return $basis->percentage($rate);
    }

    /** @return array<string, mixed> */
    private function inputs(
        CommissionPlan $plan,
        CommissionRuleVersion $version,
        MarginBreakdown $breakdown,
        Money $basis,
        Money $amount,
        bool $provisional,
    ): array {
        return [
            'strategy' => $plan->strategy,
            'ad_spend_allocation' => $plan->ad_spend_allocation,
            'rule_version' => $version->version,
            'rate_type' => $version->rate_type,
            'rate_value' => (string) $version->rate_value,
            'basis' => $basis->toDecimal(),
            'amount' => $amount->toDecimal(),
            'is_provisional' => $provisional,
            'margin' => $breakdown->toArray(),
        ];
    }

    private function attributionFor(Order $order): ?Attribution
    {
        return Attribution::query()
            ->where('attributable_type', Order::class)
            ->where('attributable_id', $order->getKey())
            ->first();
    }

    private function recipientFor(CommissionPlan $plan, ?Attribution $attribution, Order $order): ?User
    {
        if ($attribution === null) {
            return null;
        }

        return match ($plan->recipient_role) {
            'marketer' => $attribution->marketer_id === null
                ? null
                : Marketer::query()->whereKey($attribution->marketer_id)->first()?->user,
            'salesperson' => $attribution->salesperson_user_id === null
                ? null
                : User::query()->whereKey($attribution->salesperson_user_id)->first(),
            'sales_team' => $attribution->sales_team_id === null
                ? null
                : SalesTeam::query()->whereKey($attribution->sales_team_id)->first()?->manager,
            default => null,
        };
    }

    private function ruleVersionFor(CommissionPlan $plan, Order $order): ?CommissionRuleVersion
    {
        $at = $order->placed_at ?? now();

        $ruleIds = CommissionRule::query()
            ->where('commission_plan_id', $plan->getKey())
            ->where('is_active', true)
            ->pluck('id');

        if ($ruleIds->isEmpty()) {
            return null;
        }

        return CommissionRuleVersion::query()
            ->whereIn('commission_rule_id', $ruleIds)
            ->where('valid_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at))
            ->orderByDesc('valid_from')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function write(Commission $commission, ?User $actor, string $event, string $summary, ?array $before = null, ?array $after = null): CommissionEvent
    {
        return CommissionEvent::create([
            'commission_id' => $commission->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'event' => $event,
            'summary' => $summary,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
