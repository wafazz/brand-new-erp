<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Models\Commission;
use App\Models\CommissionEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommissionStateMachine
{
    /** @var array<string, array<int, string>> */
    private const ALLOWED = [
        'pending' => ['approved', 'cancelled'],
        'approved' => ['payable', 'cancelled', 'reversed'],
        'payable' => ['paid', 'reversed'],
        'paid' => ['reversed'],
        'cancelled' => [],
        'reversed' => [],
    ];

    public function reasonAgainst(Commission $commission, string $target): ?string
    {
        $current = $commission->status;

        if ($current === $target) {
            return "This commission is already {$target}.";
        }

        if (! in_array($target, self::ALLOWED[$current] ?? [], true)) {
            return "A commission that is {$current} cannot become {$target}.";
        }

        if ($target === 'payable' && $commission->is_provisional) {
            return 'This commission is still provisional. Close the period against reconciled costs before making it payable.';
        }

        if ($target === 'paid' && $commission->commission_payout_id === null) {
            return 'A commission is only paid through a payout run.';
        }

        return null;
    }

    public function canTransition(Commission $commission, string $target): bool
    {
        return $this->reasonAgainst($commission, $target) === null;
    }

    public function transition(Commission $commission, string $target, ?User $actor = null, ?string $reason = null): Commission
    {
        $blocked = $this->reasonAgainst($commission, $target);

        if ($blocked !== null) {
            throw new CommissionNotPermitted($blocked);
        }

        return DB::transaction(function () use ($commission, $target, $actor, $reason): Commission {
            $locked = Commission::query()->lockForUpdate()->findOrFail($commission->getKey());

            if ($locked->status !== $commission->status) {
                throw new CommissionNotPermitted('Someone else changed this commission. Reload and try again.');
            }

            $from = $locked->status;
            $attributes = ['status' => $target];

            if ($target === 'approved') {
                $attributes['approved_by'] = $actor?->getKey();
                $attributes['approved_at'] = now();
            }

            if ($target === 'paid') {
                $attributes['paid_at'] = now();
            }

            $locked->forceFill($attributes)->save();

            CommissionEvent::create([
                'commission_id' => $locked->getKey(),
                'actor_user_id' => $actor?->getKey(),
                'event' => 'status_changed',
                'summary' => "Status moved from {$from} to {$target}.".($reason === null ? '' : " Reason: {$reason}"),
                'before' => ['status' => $from],
                'after' => ['status' => $target],
            ]);

            return $locked->refresh();
        });
    }

    /** @return array<int, string> */
    public function availableTransitions(Commission $commission): array
    {
        return array_values(array_filter(
            self::ALLOWED[$commission->status] ?? [],
            fn (string $target): bool => $this->canTransition($commission, $target)
        ));
    }
}
