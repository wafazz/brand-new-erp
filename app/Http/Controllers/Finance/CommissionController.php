<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Domain\Commission\CommissionEngine;
use App\Domain\Commission\CommissionStateMachine;
use App\Domain\Finance\CommissionPayoutService;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CommissionController extends Controller
{
    public function __construct(
        private readonly CommissionEngine $engine,
        private readonly CommissionStateMachine $states,
        private readonly CommissionPayoutService $payouts,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Commission::class);

        $period = (string) $request->query('period', now()->format('Y-m'));
        $status = (string) $request->query('status', '');

        $base = fn () => Commission::query()
            ->visibleTo($request->user(), 'commissions.view')
            ->where('period', $period);

        $commissions = $base()
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->with(['recipient:id,name', 'order:id,order_number'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Commission $commission): array => [
                'id' => $commission->getKey(),
                'recipient' => $commission->recipient->name ?? '—',
                'role' => $commission->recipient_role,
                'order_id' => $commission->order_id,
                'order_number' => $commission->order?->order_number,
                'type' => $commission->type,
                'status' => $commission->status,
                'is_provisional' => $commission->is_provisional,
                'currency' => $commission->currency,
                'basis_amount' => (string) $commission->basis_amount,
                'rate_applied' => (string) $commission->rate_applied,
                'rate_type' => $commission->rate_type,
                'amount' => (string) $commission->amount,
            ]);

        $totals = [];

        foreach (['pending', 'approved', 'payable', 'paid', 'reversed'] as $bucket) {
            $totals[$bucket] = Money::of((string) ($base()->where('status', $bucket)->sum('amount') ?: '0'))->toDecimal();
        }

        return Inertia::render('Finance/Commissions/Index', [
            'commissions' => $commissions,
            'filters' => ['period' => $period, 'status' => $status],
            'totals' => $totals,
            'can' => [
                'approve' => $request->user()->can('commissions.approve'),
                'pay' => $request->user()->can('commissions.pay'),
            ],
        ]);
    }

    public function show(Request $request, Commission $commission): Response
    {
        $this->authorize('view', $commission);

        $commission->loadMissing(['recipient:id,name', 'order:id,order_number']);

        $user = $request->user();

        return Inertia::render('Finance/Commissions/Show', [
            'commission' => [
                'id' => $commission->getKey(),
                'recipient' => $commission->recipient->name ?? '—',
                'role' => $commission->recipient_role,
                'order_id' => $commission->order_id,
                'order_number' => $commission->order?->order_number,
                'type' => $commission->type,
                'status' => $commission->status,
                'is_provisional' => $commission->is_provisional,
                'period' => $commission->period,
                'currency' => $commission->currency,
                'basis_amount' => (string) $commission->basis_amount,
                'rate_type' => $commission->rate_type,
                'rate_applied' => (string) $commission->rate_applied,
                'amount' => (string) $commission->amount,
                'calc_inputs' => $commission->calc_inputs,
            ],
            'explanation' => $this->engine->explain($commission),
            'transitions' => $this->states->availableTransitions($commission),
            'permissions' => [
                'approve' => $user->can('approve', $commission),
                'pay' => $user->can('pay', $commission),
            ],
        ]);
    }

    public function transition(Request $request, Commission $commission): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'payable', 'paid', 'reversed'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->authorize($data['status'] === 'paid' ? 'pay' : 'approve', $commission);

        try {
            if ($data['status'] === 'payable') {
                $this->payouts->markPayable($commission, $request->user());
            } elseif ($data['status'] === 'reversed') {
                $reason = $data['reason'] ?? null;

                abort_if($reason === null, 422, 'A reversal needs a reason.');

                $this->engine->reverse($commission, $reason, $request->user());
            } else {
                $this->states->transition($commission, $data['status'], $request->user(), $data['reason'] ?? null);
            }
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Commission updated.');
    }
}
