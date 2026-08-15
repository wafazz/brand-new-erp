<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Numbering\DocumentNumberService;
use App\Models\CompanyUser;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function workingDaysBetween(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = 0;
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }

            $cursor = $cursor->addDay();
        }

        return number_format($days, 2, '.', '');
    }

    public function takenIn(User $employee, LeaveType $type, int $year): string
    {
        $days = LeaveRequest::query()
            ->where('user_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->whereIn('status', ['pending', 'approved'])
            ->whereRaw('extract(year from starts_on) = ?', [$year])
            ->sum('days');

        return number_format((float) $days, 2, '.', '');
    }

    public function remainingFor(User $employee, LeaveType $type, int $year): string
    {
        return bcsub((string) $type->days_per_year, $this->takenIn($employee, $type, $year), 2);
    }

    public function request(User $employee, LeaveType $type, CarbonImmutable $from, CarbonImmutable $to, string $reason): LeaveRequest
    {
        if (! $type->is_active) {
            throw new LeaveRefused("{$type->name} is no longer offered.");
        }

        if ($to->lessThan($from)) {
            throw new LeaveRefused('Leave cannot end before it starts.');
        }

        if (trim($reason) === '') {
            throw new LeaveRefused('Say why. Somebody has to decide on this without being able to ask you.');
        }

        $days = $this->workingDaysBetween($from, $to);

        if (bccomp($days, '0', 2) !== 1) {
            throw new LeaveRefused('Those dates are all weekend, so no working days would be taken.');
        }

        $remaining = $this->remainingFor($employee, $type, (int) $from->format('Y'));

        if (bccomp((string) $type->days_per_year, '0', 2) === 1 && bccomp($days, $remaining, 2) === 1) {
            throw new LeaveRefused(
                "That is {$days} days of {$type->name} and only {$remaining} remain this year."
            );
        }

        $this->assertNoOverlap($employee, $from, $to);

        try {
            return DB::transaction(fn (): LeaveRequest => LeaveRequest::create([
                'leave_type_id' => $type->getKey(),
                'user_id' => $employee->getKey(),
                'reference' => $this->numbers->next('leave', 'LV'),
                'status' => 'pending',
                'starts_on' => $from,
                'ends_on' => $to,
                'days' => $days,
                'reason' => trim($reason),
            ]));
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'leave_requests_one_live_per_day')) {
                throw new LeaveRefused('You have already asked for exactly those dates.');
            }

            throw $exception;
        }
    }

    public function decide(LeaveRequest $request, User $decider, string $decision, ?string $note = null): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new LeaveRefused("This request has already been {$request->status}.");
        }

        if ($request->user_id === $decider->getKey()) {
            throw new LeaveRefused('You cannot decide on your own leave.');
        }

        if ($decision === 'rejected' && trim((string) $note) === '') {
            throw new LeaveRefused('A rejection needs a reason. The person has to know what to do next.');
        }

        return DB::transaction(function () use ($request, $decider, $decision, $note): LeaveRequest {
            $locked = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status !== 'pending') {
                throw new LeaveRefused('Somebody decided on this a moment ago.');
            }

            $locked->forceFill([
                'status' => $decision,
                'decided_by' => $decider->getKey(),
                'decision_note' => $note,
                'decided_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    public function cancel(LeaveRequest $request, User $actor): LeaveRequest
    {
        if ($request->user_id !== $actor->getKey()) {
            throw new LeaveRefused('Only the person who asked for leave can withdraw it.');
        }

        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw new LeaveRefused("This request is already {$request->status}.");
        }

        if ($request->starts_on !== null && $request->starts_on->isPast()) {
            throw new LeaveRefused('This leave has already started. Ask your manager to sort it out.');
        }

        $request->forceFill(['status' => 'cancelled'])->save();

        return $request->refresh();
    }

    public function mayDecideFor(User $decider, LeaveRequest $request): bool
    {
        if ($request->user_id === $decider->getKey()) {
            return false;
        }

        if ($decider->can('leave.configure')) {
            return true;
        }

        return CompanyUser::query()
            ->where('user_id', $request->user_id)
            ->where('manager_id', $decider->getKey())
            ->where('is_active', true)
            ->exists();
    }

    private function assertNoOverlap(User $employee, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $clash = LeaveRequest::query()
            ->where('user_id', $employee->getKey())
            ->whereIn('status', ['pending', 'approved'])
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->first();

        if ($clash !== null) {
            throw new LeaveRefused(
                "That overlaps {$clash->reference}, which runs from ".
                "{$clash->starts_on?->toDateString()} to {$clash->ends_on?->toDateString()}."
            );
        }
    }
}
