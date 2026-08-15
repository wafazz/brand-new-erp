<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AgeingReport
{
    /** @var array<string, array{0: int, 1: ?int}> */
    public const BUCKETS = [
        '0-30' => [0, 30],
        '31-60' => [31, 60],
        '61-90' => [61, 90],
        '90+' => [91, null],
    ];

    /** @return array<string, string> */
    public function buckets(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $totals = array_fill_keys(array_keys(self::BUCKETS), Money::zero());

        foreach ($this->outstandingInvoices() as $invoice) {
            $due = $invoice->due_at ?? $invoice->issued_at ?? $asOf;
            $days = (int) $due->startOfDay()->diffInDays($asOf->startOfDay(), false);
            $bucket = $this->bucketFor($days);

            $outstanding = Money::of((string) $invoice->total, $invoice->currency)
                ->minus(Money::of((string) $invoice->paid_amount, $invoice->currency));

            $totals[$bucket] = $totals[$bucket]->plus($outstanding);
        }

        return array_map(static fn (Money $m): string => $m->toDecimal(), $totals);
    }

    /** @return array<string, string> */
    public function reconcile(): array
    {
        $invoiced = Money::zero();
        $paid = Money::zero();
        $outstanding = Money::zero();

        foreach (Invoice::query()->whereNot('status', 'void')->get() as $invoice) {
            $total = Money::of((string) $invoice->total, $invoice->currency);
            $settled = Money::of((string) $invoice->paid_amount, $invoice->currency);

            $invoiced = $invoiced->plus($total);
            $paid = $paid->plus($settled);
            $outstanding = $outstanding->plus($total->minus($settled));
        }

        return [
            'invoiced' => $invoiced->toDecimal(),
            'paid' => $paid->toDecimal(),
            'outstanding' => $outstanding->toDecimal(),
            'reconciles' => $invoiced->equals($paid->plus($outstanding)) ? 'yes' : 'no',
        ];
    }

    /** @return Collection<int, Invoice> */
    private function outstandingInvoices(): Collection
    {
        return Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereColumn('paid_amount', '<', 'total')
            ->get();
    }

    private function bucketFor(int $daysOverdue): string
    {
        foreach (self::BUCKETS as $name => [$from, $to]) {
            if ($daysOverdue >= $from && ($to === null || $daysOverdue <= $to)) {
                return $name;
            }
        }

        return '0-30';
    }
}
