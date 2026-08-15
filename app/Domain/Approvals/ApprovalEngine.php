<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Models\ApprovalAction;
use App\Models\ApprovalFlow;
use App\Models\ApprovalLevel;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApprovalEngine
{
    public function submit(Model $approvable, string $amount, ?User $actor = null): ApprovalRequest
    {
        $flow = ApprovalFlow::query()
            ->where('approvable_type', $approvable::class)
            ->where('is_active', true)
            ->first();

        if ($flow === null) {
            throw new ApprovalNotPermitted(
                'No approval flow is configured for '.class_basename($approvable).'.'
            );
        }

        return DB::transaction(function () use ($flow, $approvable, $amount, $actor): ApprovalRequest {
            $request = ApprovalRequest::create([
                'approval_flow_id' => $flow->getKey(),
                'requested_by' => $actor?->getKey(),
                'approvable_type' => $approvable::class,
                'approvable_id' => $approvable->getKey(),
                'amount' => $amount,
                'current_sequence' => $this->firstSequence($flow, $amount),
            ]);

            $this->write($request, $actor, 'submit', null);

            return $request->refresh();
        });
    }

    public function reasonAgainst(ApprovalRequest $request, User $user): ?string
    {
        if ($request->status !== 'pending') {
            return "This request has already been {$request->status}.";
        }

        $level = $this->levelFor($request);

        if ($level === null) {
            return 'No approval level is configured for this amount.';
        }

        if ($request->requested_by === $user->getKey()) {
            return 'You cannot approve your own request.';
        }

        if (! $this->isApprover($level, $user)) {
            return 'You are not an approver at this level.';
        }

        return null;
    }

    public function approve(ApprovalRequest $request, User $user, ?string $comment = null): ApprovalRequest
    {
        $this->assertPermitted($request, $user);

        return DB::transaction(function () use ($request, $user, $comment): ApprovalRequest {
            $locked = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $this->write($locked, $user, 'approve', $comment);

            $next = $this->nextSequence($locked);

            if ($next === null) {
                $locked->forceFill(['status' => 'approved', 'resolved_at' => now()])->save();
            } else {
                $locked->forceFill(['current_sequence' => $next])->save();
            }

            return $locked->refresh();
        });
    }

    public function reject(ApprovalRequest $request, User $user, string $comment): ApprovalRequest
    {
        $this->assertPermitted($request, $user);

        return DB::transaction(function () use ($request, $user, $comment): ApprovalRequest {
            $locked = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $this->write($locked, $user, 'reject', $comment);
            $locked->forceFill(['status' => 'rejected', 'resolved_at' => now()])->save();

            return $locked->refresh();
        });
    }

    public function returnForRevision(ApprovalRequest $request, User $user, string $comment): ApprovalRequest
    {
        $this->assertPermitted($request, $user);

        return DB::transaction(function () use ($request, $user, $comment): ApprovalRequest {
            $locked = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            $this->write($locked, $user, 'return_for_revision', $comment);
            $locked->forceFill(['status' => 'returned', 'resolved_at' => now()])->save();

            return $locked->refresh();
        });
    }

    private function assertPermitted(ApprovalRequest $request, User $user): void
    {
        $reason = $this->reasonAgainst($request, $user);

        if ($reason !== null) {
            throw new ApprovalNotPermitted($reason);
        }
    }

    private function levelFor(ApprovalRequest $request): ?ApprovalLevel
    {
        return $this->levelsFor($request->flow, (string) $request->amount)
            ->firstWhere('sequence', $request->current_sequence);
    }

    private function nextSequence(ApprovalRequest $request): ?int
    {
        $next = $this->levelsFor($request->flow, (string) $request->amount)
            ->firstWhere(fn (ApprovalLevel $level): bool => $level->sequence > $request->current_sequence);

        return $next?->sequence;
    }

    private function firstSequence(ApprovalFlow $flow, string $amount): int
    {
        $first = $this->levelsFor($flow, $amount)->first();

        return $first === null ? 1 : $first->sequence;
    }

    /** @return Collection<int, ApprovalLevel> */
    private function levelsFor(ApprovalFlow $flow, string $amount): Collection
    {
        return $flow->levels()
            ->orderBy('sequence')
            ->get()
            ->filter(function (ApprovalLevel $level) use ($amount): bool {
                if (bccomp($amount, (string) $level->min_amount, 4) === -1) {
                    return false;
                }

                return $level->max_amount === null || bccomp($amount, (string) $level->max_amount, 4) <= 0;
            })
            ->values();
    }

    private function isApprover(ApprovalLevel $level, User $user): bool
    {
        if ($level->approver_user_id !== null && $level->approver_user_id === $user->getKey()) {
            return true;
        }

        return $level->approver_role_id !== null
            && $user->roles()->whereKey($level->approver_role_id)->exists();
    }

    private function write(ApprovalRequest $request, ?User $actor, string $action, ?string $comment): ApprovalAction
    {
        return ApprovalAction::create([
            'approval_request_id' => $request->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'sequence' => $request->current_sequence,
            'action' => $action,
            'comment' => $comment,
        ]);
    }

    public function describeAmount(ApprovalRequest $request): string
    {
        return Money::of((string) $request->amount)->format();
    }
}
