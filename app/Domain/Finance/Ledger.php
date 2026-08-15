<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Numbering\DocumentNumberService;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Ledger
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function account(AccountCode $code): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => $code->value],
            ['name' => $code->label(), 'type' => $code->type()]
        );
    }

    /**
     * @param  array<int, array{account: AccountCode, debit?: string, credit?: string, memo?: string}>  $lines
     */
    public function post(
        string $description,
        array $lines,
        ?Model $source = null,
        ?User $actor = null,
        string $currency = Money::DEFAULT_CURRENCY,
    ): JournalEntry {
        $debit = Money::zero($currency);
        $credit = Money::zero($currency);

        foreach ($lines as $line) {
            $debit = $debit->plus(Money::of($line['debit'] ?? '0', $currency));
            $credit = $credit->plus(Money::of($line['credit'] ?? '0', $currency));
        }

        if (! $debit->equals($credit)) {
            throw new UnbalancedJournalEntry(
                "This entry does not balance: debits {$debit->format()} against credits {$credit->format()}."
            );
        }

        if ($debit->isZero()) {
            throw new UnbalancedJournalEntry('An entry with no value posts nothing.');
        }

        return DB::transaction(function () use ($description, $lines, $source, $actor, $currency, $debit, $credit): JournalEntry {
            $entry = JournalEntry::create([
                'posted_by' => $actor?->getKey(),
                'reference' => $this->numbers->next('journal', 'JE'),
                'description' => $description,
                'source_type' => $source === null ? null : $source::class,
                'source_id' => $source?->getKey(),
                'currency' => $currency,
                'posted_at' => now(),
            ]);

            foreach ($lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->getKey(),
                    'account_id' => $this->account($line['account'])->getKey(),
                    'debit' => $line['debit'] ?? '0',
                    'credit' => $line['credit'] ?? '0',
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            $entry->forceFill([
                'total_debit' => $debit->toDecimal(),
                'total_credit' => $credit->toDecimal(),
            ])->save();

            return $entry->refresh();
        });
    }

    public function balanceOf(AccountCode $code): Money
    {
        $account = $this->account($code);

        $debit = (string) JournalLine::query()->where('account_id', $account->getKey())->sum('debit');
        $credit = (string) JournalLine::query()->where('account_id', $account->getKey())->sum('credit');

        $net = in_array($code->type(), ['asset', 'expense'], true)
            ? bcsub($debit, $credit, 4)
            : bcsub($credit, $debit, 4);

        return Money::of($net);
    }

    public function trialBalance(): Money
    {
        $debit = (string) JournalLine::query()->sum('debit');
        $credit = (string) JournalLine::query()->sum('credit');

        return Money::of(bcsub($debit, $credit, 4));
    }
}
