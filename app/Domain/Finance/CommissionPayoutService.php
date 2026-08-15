<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Commission\CommissionStateMachine;
use App\Domain\Numbering\DocumentNumberService;
use App\Models\BankAccount;
use App\Models\CashFlow;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\CommissionPayoutItem;
use App\Models\CommissionRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommissionPayoutService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly CommissionStateMachine $states,
    ) {}

    public function markPayable(Commission $commission, ?User $actor = null): Commission
    {
        return DB::transaction(function () use ($commission, $actor): Commission {
            $moved = $this->states->transition($commission, 'payable', $actor);

            $amount = Money::of((string) $moved->amount, $moved->currency);

            $this->ledger->post(
                "Commission accrued for {$moved->recipient?->name}",
                [
                    ['account' => AccountCode::CommissionExpense, 'debit' => $amount->toDecimal(), 'memo' => $moved->period],
                    ['account' => AccountCode::CommissionPayable, 'credit' => $amount->toDecimal(), 'memo' => $moved->getKey()],
                ],
                $moved,
                $actor,
                $moved->currency,
            );

            return $moved;
        });
    }

    public function createPayout(string $period, ?User $actor = null): CommissionPayout
    {
        return DB::transaction(function () use ($period, $actor): CommissionPayout {
            $payable = Commission::query()
                ->where('period', $period)
                ->where('status', 'payable')
                ->whereNull('commission_payout_id')
                ->lockForUpdate()
                ->get();

            if ($payable->isEmpty()) {
                throw new RuntimeException("No payable commission is waiting for {$period}.");
            }

            $payout = CommissionPayout::create([
                'created_by' => $actor?->getKey(),
                'reference' => $this->numbers->next('commission_payout', 'CPO'),
                'period' => $period,
                'status' => 'draft',
            ]);

            $total = Money::zero();

            foreach ($payable as $commission) {
                $amount = Money::of((string) $commission->amount, $commission->currency);
                $total = $total->plus($amount);

                CommissionPayoutItem::create([
                    'commission_payout_id' => $payout->getKey(),
                    'commission_id' => $commission->getKey(),
                    'amount' => $amount->toDecimal(),
                ]);

                $commission->forceFill(['commission_payout_id' => $payout->getKey()])->save();
            }

            foreach ($payable->groupBy('recipient_user_id') as $recipientId => $group) {
                $recipient = User::query()->find($recipientId);

                CommissionRequest::create([
                    'commission_payout_id' => $payout->getKey(),
                    'recipient_user_id' => $recipientId,
                    'bank_name' => 'Not supplied',
                    'bank_account' => 'Not supplied',
                    'bank_holder' => $recipient === null ? 'Unknown' : $recipient->name,
                    'amount' => Money::sum($group->map(
                        fn (Commission $c): Money => Money::of((string) $c->amount, $c->currency)
                    ))->toDecimal(),
                ]);
            }

            $payout->forceFill(['total_amount' => $total->toDecimal()])->save();

            return $payout->refresh();
        });
    }

    public function pay(CommissionPayout $payout, ?BankAccount $bank = null, ?User $actor = null): CommissionPayout
    {
        if ($payout->status === 'paid') {
            throw new RuntimeException("Payout {$payout->reference} has already been paid.");
        }

        return DB::transaction(function () use ($payout, $bank, $actor): CommissionPayout {
            $locked = CommissionPayout::query()->lockForUpdate()->findOrFail($payout->getKey());

            if ($locked->status === 'paid') {
                throw new RuntimeException("Payout {$locked->reference} has already been paid.");
            }

            $commissions = Commission::query()->where('commission_payout_id', $locked->getKey())->get();

            foreach ($commissions as $commission) {
                $this->states->transition($commission, 'paid', $actor, "Paid in {$locked->reference}.");
            }

            $total = Money::of((string) $locked->total_amount);

            $entry = $this->ledger->post(
                "Commission payout {$locked->reference}",
                [
                    ['account' => AccountCode::CommissionPayable, 'debit' => $total->toDecimal(), 'memo' => $locked->period],
                    ['account' => AccountCode::Bank, 'credit' => $total->toDecimal(), 'memo' => $locked->reference],
                ],
                $locked,
                $actor,
            );

            CashFlow::create([
                'bank_account_id' => $bank?->getKey(),
                'journal_entry_id' => $entry->getKey(),
                'recorded_by' => $actor?->getKey(),
                'direction' => 'out',
                'category' => 'commission',
                'description' => "Commission payout {$locked->reference}",
                'amount' => $total->toDecimal(),
                'source_type' => CommissionPayout::class,
                'source_id' => $locked->getKey(),
                'occurred_on' => now()->toDateString(),
            ]);

            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return $locked->refresh();
        });
    }
}
