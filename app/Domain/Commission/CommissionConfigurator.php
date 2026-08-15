<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CommissionConfigurator
{
    public const STRATEGIES = ['percentage_of_value', 'percentage_of_margin', 'fixed_per_order', 'fixed_per_unit'];

    public const RECIPIENTS = ['marketer', 'salesperson', 'sales_team', 'upline'];

    public const ALLOCATIONS = ['pro_rata_by_order_value', 'equal_per_order', 'pro_rata_by_margin', 'excluded'];

    public const RATE_TYPES = ['percent', 'fixed'];

    /** @param array<string, mixed> $attributes */
    public function createPlan(array $attributes): CommissionPlan
    {
        $this->assertStrategyMatchesRate($attributes['strategy'], null);

        return CommissionPlan::create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function updatePlan(CommissionPlan $plan, array $attributes): CommissionPlan
    {
        $strategyChanged = isset($attributes['strategy']) && $attributes['strategy'] !== $plan->strategy;
        $recipientChanged = isset($attributes['recipient_role']) && $attributes['recipient_role'] !== $plan->recipient_role;

        if (($strategyChanged || $recipientChanged) && $this->hasAccruals($plan)) {
            throw new CommissionConfigurationRefused(
                'This plan has already paid out against its current strategy. Deactivate it and create a new plan, '.
                'so what was accrued still explains itself.'
            );
        }

        $plan->update($attributes);

        return $plan->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function createRule(CommissionPlan $plan, array $attributes): CommissionRule
    {
        return CommissionRule::create([...$attributes, 'commission_plan_id' => $plan->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function publishVersion(CommissionRule $rule, array $attributes, ?User $actor = null): CommissionRuleVersion
    {
        $rateType = (string) $attributes['rate_type'];
        $rateValue = (string) $attributes['rate_value'];

        $this->assertRateIsSane($rateType, $rateValue);
        $this->assertStrategyMatchesRate($rule->plan->strategy ?? '', $rateType);

        $validFrom = $attributes['valid_from'];

        return DB::transaction(function () use ($rule, $attributes, $rateType, $rateValue, $validFrom, $actor): CommissionRuleVersion {
            $current = CommissionRuleVersion::query()
                ->where('commission_rule_id', $rule->getKey())
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($current !== null && $current->valid_from !== null && $validFrom instanceof CarbonInterface
                && $validFrom->lessThanOrEqualTo($current->valid_from)) {
                throw new CommissionConfigurationRefused(
                    'A new rate must start after the one it replaces, which starts '.
                    $current->valid_from->toDayDateTimeString().'.'
                );
            }

            $version = CommissionRuleVersion::create([
                'commission_rule_id' => $rule->getKey(),
                'created_by' => $actor?->getKey(),
                'version' => $current === null ? 1 : $current->version + 1,
                'rate_type' => $rateType,
                'rate_value' => $rateValue,
                'tier_config' => $attributes['tier_config'] ?? null,
                'conditions' => $attributes['conditions'] ?? null,
                'valid_from' => $validFrom,
                'valid_to' => $attributes['valid_to'] ?? null,
            ]);

            return $version->refresh();
        });
    }

    public function versionInForce(CommissionRule $rule, ?CarbonInterface $at = null): ?CommissionRuleVersion
    {
        $moment = $at ?? now();

        return CommissionRuleVersion::query()
            ->where('commission_rule_id', $rule->getKey())
            ->where('valid_from', '<=', $moment)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $moment))
            ->orderByDesc('valid_from')
            ->orderByDesc('version')
            ->first();
    }

    public function hasAccruals(CommissionPlan $plan): bool
    {
        return Commission::query()->where('commission_plan_id', $plan->getKey())->exists();
    }

    private function assertRateIsSane(string $rateType, string $rateValue): void
    {
        if (bccomp($rateValue, '0', 4) !== 1) {
            throw new CommissionConfigurationRefused('A rate must be greater than zero.');
        }

        if ($rateType === 'percent' && bccomp($rateValue, '100', 4) === 1) {
            throw new CommissionConfigurationRefused(
                "A percentage rate of {$rateValue}% would pay out more than the amount it is calculated on."
            );
        }
    }

    private function assertStrategyMatchesRate(string $strategy, ?string $rateType): void
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new CommissionConfigurationRefused("[{$strategy}] is not a commission strategy.");
        }

        if ($rateType === null) {
            return;
        }

        $expected = str_starts_with($strategy, 'percentage_of') ? 'percent' : 'fixed';

        if ($rateType !== $expected) {
            throw new CommissionConfigurationRefused(
                "A {$strategy} plan needs a {$expected} rate, not a {$rateType} one."
            );
        }
    }
}
