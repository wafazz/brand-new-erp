<?php

declare(strict_types=1);

namespace App\Domain\Attribution;

use App\Models\Attribution;
use App\Models\AttributionTouch;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\SalesTeamMember;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttributionService
{
    /** @param array<string, mixed> $dimensions */
    public function recordTouch(Model $subject, array $dimensions, ?CarbonInterface $occurredAt = null): AttributionTouch
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->writeTouch($subject, $dimensions, $occurredAt);
            } catch (QueryException $exception) {
                if ($attempt === 3 || $exception->getCode() !== '23505') {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Could not record an attribution touch after 3 attempts.');
    }

    /** @param array<string, mixed> $dimensions */
    private function writeTouch(Model $subject, array $dimensions, ?CarbonInterface $occurredAt): AttributionTouch
    {
        return DB::transaction(function () use ($subject, $dimensions, $occurredAt): AttributionTouch {
            $last = AttributionTouch::query()
                ->where('subject_type', $subject::class)
                ->where('subject_id', $subject->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            $next = ($last === null ? 0 : (int) $last->sequence) + 1;

            return AttributionTouch::create([
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'sequence' => $next,
                'channel_id' => $dimensions['channel_id'] ?? null,
                'campaign_id' => $dimensions['campaign_id'] ?? null,
                'marketer_id' => $dimensions['marketer_id'] ?? null,
                'referral_code_id' => $dimensions['referral_code_id'] ?? null,
                'source' => $dimensions['source'] ?? null,
                'medium' => $dimensions['medium'] ?? null,
                'raw' => $dimensions['raw'] ?? null,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }

    public function firstTouchFor(Model $subject): ?AttributionTouch
    {
        return AttributionTouch::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->orderBy('sequence')
            ->first();
    }

    public function lastTouchFor(Model $subject): ?AttributionTouch
    {
        return AttributionTouch::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('sequence')
            ->first();
    }

    public function attributionFor(Model $subject): ?Attribution
    {
        return Attribution::query()
            ->where('attributable_type', $subject::class)
            ->where('attributable_id', $subject->getKey())
            ->first();
    }

    public function resolveFor(Model $subject): Attribution
    {
        $existing = $this->attributionFor($subject);

        if ($existing !== null) {
            return $existing;
        }

        $touch = $this->firstTouchFor($subject) ?? $this->inheritedTouchFor($subject);

        return Attribution::create([
            'attributable_type' => $subject::class,
            'attributable_id' => $subject->getKey(),
            'touch_type' => 'first',
            'channel_id' => $touch?->channel_id,
            'campaign_id' => $touch?->campaign_id,
            'marketer_id' => $touch?->marketer_id,
            'referral_code_id' => $touch?->referral_code_id,
            'source' => $touch?->source,
            'medium' => $touch?->medium,
            'raw' => $touch?->raw,
            'captured_at' => $touch === null ? now() : $touch->occurred_at,
        ]);
    }

    public function freezeOntoOrder(
        Order $order,
        ?Lead $lead = null,
        ?User $salesperson = null,
    ): Attribution {
        $existing = $this->attributionFor($order);

        if ($existing !== null) {
            throw new RuntimeException(
                "Order {$order->order_number} is already attributed. Attribution is frozen once an order exists, ".
                'because commission is paid on it.'
            );
        }

        $inherited = $this->inheritedFrom($order, $lead);
        $closer = $salesperson ?? $order->owner;

        return Attribution::create([
            'attributable_type' => $order::class,
            'attributable_id' => $order->getKey(),
            'touch_type' => 'first',
            'channel_id' => $inherited?->channel_id,
            'campaign_id' => $inherited?->campaign_id,
            'marketer_id' => $inherited?->marketer_id,
            'referral_code_id' => $inherited?->referral_code_id,
            'lead_id' => $lead?->getKey(),
            'salesperson_user_id' => $closer?->getKey(),
            'sales_team_id' => $closer === null ? null : $this->teamFor($closer),
            'branch_id' => $order->branch_id,
            'source' => $inherited?->source,
            'medium' => $inherited?->medium,
            'raw' => $inherited?->raw,
            'captured_at' => $inherited === null ? now() : $inherited->captured_at,
        ]);
    }

    private function inheritedTouchFor(Model $subject): ?AttributionTouch
    {
        if (! $subject instanceof Customer) {
            return null;
        }

        $lead = Lead::query()
            ->where('converted_customer_id', $subject->getKey())
            ->orderBy('captured_at')
            ->first();

        return $lead === null ? null : $this->firstTouchFor($lead);
    }

    private function inheritedFrom(Order $order, ?Lead $lead): ?Attribution
    {
        if ($lead !== null) {
            $fromLead = $this->attributionFor($lead) ?? $this->resolveFor($lead);

            if ($this->hasAnyDimension($fromLead)) {
                return $fromLead;
            }
        }

        if ($order->customer_id === null) {
            return null;
        }

        $customer = Customer::query()->find($order->customer_id);

        if ($customer === null) {
            return null;
        }

        $fromCustomer = $this->attributionFor($customer);

        return $this->hasAnyDimension($fromCustomer) ? $fromCustomer : null;
    }

    private function hasAnyDimension(?Attribution $attribution): bool
    {
        if ($attribution === null) {
            return false;
        }

        return $attribution->channel_id !== null
            || $attribution->campaign_id !== null
            || $attribution->marketer_id !== null
            || $attribution->referral_code_id !== null
            || $attribution->source !== null;
    }

    private function teamFor(User $user): ?string
    {
        return SalesTeamMember::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->value('sales_team_id');
    }
}
