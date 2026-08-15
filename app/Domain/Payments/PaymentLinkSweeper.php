<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Invoice;
use Illuminate\Support\Collection;
use Throwable;

class PaymentLinkSweeper
{
    public function __construct(
        private readonly PaymentLinkService $links,
        private readonly BillplzClient $client,
    ) {}

    public function sweep(): SweepResult
    {
        $result = new SweepResult;

        if (! $this->client->configured()) {
            $result->skippedUnconfigured = true;

            return $result;
        }

        foreach ($this->awaiting() as $invoice) {
            try {
                $this->links->createFor($invoice);
                $result->raised++;
            } catch (Throwable $exception) {
                $result->failed[] = "{$invoice->invoice_number}: {$exception->getMessage()}";
            }
        }

        return $result;
    }

    /** @return Collection<int, Invoice> */
    public function awaiting(): Collection
    {
        return Invoice::query()
            ->where('currency', 'MYR')
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNull('voided_at')
            ->whereColumn('paid_amount', '<', 'total')
            ->whereHas('order.subscription', fn ($query) => $query->where('collect_online', true))
            ->whereDoesntHave('paymentIntents', fn ($query) => $query
                ->where('status', 'pending')
                ->whereNotNull('pay_url'))
            ->orderBy('issued_at')
            ->get();
    }
}
