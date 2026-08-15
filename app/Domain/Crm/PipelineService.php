<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PipelineStage;
use App\Models\SalesActivity;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PipelineService
{
    public const CONTACT_TYPES = ['call', 'whatsapp', 'email', 'meeting', 'visit', 'note'];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function logContact(Lead $lead, array $attributes, User $actor): SalesActivity
    {
        if (! in_array($attributes['type'], self::CONTACT_TYPES, true)) {
            throw new PipelineRefused("[{$attributes['type']}] is not a way of contacting somebody.");
        }

        $summary = trim((string) $attributes['summary']);

        if ($summary === '') {
            throw new PipelineRefused('Say what happened. A contact with no summary tells the next person nothing.');
        }

        $followUp = $attributes['follow_up_at'] ?? null;

        if ($followUp instanceof CarbonInterface && $followUp->isPast()) {
            throw new PipelineRefused('A follow-up has to be in the future, or nobody will be reminded.');
        }

        return DB::transaction(function () use ($lead, $attributes, $summary, $followUp, $actor): SalesActivity {
            $activity = SalesActivity::create([
                'user_id' => $actor->getKey(),
                'lead_id' => $lead->getKey(),
                'customer_id' => $lead->converted_customer_id,
                'type' => $attributes['type'],
                'summary' => $summary,
                'note' => $attributes['note'] ?? null,
                'occurred_at' => $attributes['occurred_at'] ?? now(),
                'follow_up_at' => $followUp,
            ]);

            LeadActivity::create([
                'lead_id' => $lead->getKey(),
                'user_id' => $actor->getKey(),
                'type' => $attributes['type'] === 'visit' ? 'meeting' : $attributes['type'],
                'summary' => $summary,
                'occurred_at' => $activity->occurred_at,
            ]);

            return $activity->refresh();
        });
    }

    public function moveToStage(Lead $lead, PipelineStage $stage, User $actor, ?string $note = null): Lead
    {
        if ($lead->converted_order_id !== null) {
            throw new PipelineRefused('This lead has already been converted, so it no longer moves through the pipeline.');
        }

        if ($lead->pipeline_stage_id === $stage->getKey()) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $stage, $actor, $note): Lead {
            $from = $lead->stage;

            $lead->forceFill([
                'pipeline_stage_id' => $stage->getKey(),
                'status' => match (true) {
                    (bool) $stage->is_won => 'won',
                    (bool) $stage->is_lost => 'lost',
                    default => $lead->status === 'new' ? 'contacted' : $lead->status,
                },
            ])->save();

            LeadActivity::create([
                'lead_id' => $lead->getKey(),
                'user_id' => $actor->getKey(),
                'type' => 'status_changed',
                'summary' => trim(
                    'Moved from '.($from->name ?? 'no stage')." to {$stage->name}.".($note === null ? '' : " {$note}")
                ),
                'occurred_at' => now(),
            ]);

            return $lead->refresh();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function board(User $actor): array
    {
        $stages = PipelineStage::query()->orderBy('sort')->get();

        $leads = Lead::query()
            ->visibleTo($actor, 'leads.view')
            ->whereNotIn('status', ['won', 'lost'])
            ->with(['assignee:id,name'])
            ->orderByDesc('estimated_value')
            ->get();

        $board = [];

        foreach ($stages as $stage) {
            $inStage = $leads->where('pipeline_stage_id', $stage->getKey());
            $value = Money::zero();

            foreach ($inStage as $lead) {
                $value = $value->plus(Money::of((string) $lead->estimated_value));
            }

            $board[] = [
                'id' => $stage->getKey(),
                'name' => $stage->name,
                'probability' => (string) $stage->probability,
                'is_won' => (bool) $stage->is_won,
                'is_lost' => (bool) $stage->is_lost,
                'value' => $value->toDecimal(),
                'weighted' => $value->percentage((string) $stage->probability)->toDecimal(),
                'leads' => $inStage->map(fn (Lead $lead): array => [
                    'id' => $lead->getKey(),
                    'reference' => $lead->reference,
                    'name' => $lead->name,
                    'assignee' => $lead->assignee?->name,
                    'estimated_value' => (string) $lead->estimated_value,
                ])->values()->all(),
            ];
        }

        $unstaged = $leads->whereNull('pipeline_stage_id');

        if ($unstaged->isNotEmpty()) {
            $value = Money::zero();

            foreach ($unstaged as $lead) {
                $value = $value->plus(Money::of((string) $lead->estimated_value));
            }

            array_unshift($board, [
                'id' => null,
                'name' => 'No stage yet',
                'probability' => '0',
                'is_won' => false,
                'is_lost' => false,
                'value' => $value->toDecimal(),
                'weighted' => '0.0000',
                'leads' => $unstaged->map(fn (Lead $lead): array => [
                    'id' => $lead->getKey(),
                    'reference' => $lead->reference,
                    'name' => $lead->name,
                    'assignee' => $lead->assignee?->name,
                    'estimated_value' => (string) $lead->estimated_value,
                ])->values()->all(),
            ]);
        }

        return $board;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function followUpsDue(User $actor, ?CarbonInterface $by = null): array
    {
        $deadline = $by ?? now()->endOfDay();

        return SalesActivity::query()
            ->visibleTo($actor, 'leads.view')
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $deadline)
            ->with(['lead:id,reference,name,status', 'customer:id,code,name'])
            ->orderBy('follow_up_at')
            ->limit(50)
            ->get()
            ->reject(fn (SalesActivity $activity): bool => in_array($activity->lead?->status, ['won', 'lost'], true))
            ->map(fn (SalesActivity $activity): array => [
                'id' => $activity->getKey(),
                'lead_id' => $activity->lead_id,
                'subject' => $activity->lead->name ?? $activity->customer->name ?? 'Unknown',
                'reference' => $activity->lead->reference ?? $activity->customer->code ?? null,
                'type' => $activity->type,
                'summary' => $activity->summary,
                'follow_up_at' => $activity->follow_up_at?->toDayDateTimeString(),
                'overdue' => $activity->follow_up_at?->isPast() ?? false,
            ])
            ->values()
            ->all();
    }
}
