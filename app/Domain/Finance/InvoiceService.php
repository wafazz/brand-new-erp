<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Numbering\DocumentNumberService;
use App\Models\BankAccount;
use App\Models\CashFlow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoiceService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function issueFromOrder(Order $order, int $termsDays = 30, ?User $actor = null): Invoice
    {
        $existing = Invoice::query()->where('order_id', $order->getKey())->whereNot('status', 'void')->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $termsDays, $actor): Invoice {
            $customer = $order->customer;

            $invoice = Invoice::create([
                'branch_id' => $order->branch_id,
                'order_id' => $order->getKey(),
                'customer_id' => $order->customer_id,
                'issued_by' => $actor?->getKey(),
                'invoice_number' => $this->numbers->next('invoice', 'INV'),
                'status' => 'issued',
                'customer_name' => $order->customer_name,
                'customer_tax_no' => $customer?->tax_no,
                'currency' => $order->currency,
                'issued_at' => now(),
                'due_at' => now()->addDays($termsDays),
            ]);

            foreach ($order->items()->get() as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->getKey(),
                    'order_item_id' => $item->getKey(),
                    'sku' => $item->sku,
                    'description' => $item->product_name.($item->variant_name === null ? '' : ' — '.$item->variant_name),
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'discount_amount' => (string) $item->discount_amount,
                    'tax_amount' => (string) $item->tax_amount,
                    'line_total' => (string) $item->line_total,
                ]);
            }

            $invoice->forceFill([
                'subtotal' => (string) $order->subtotal,
                'discount_amount' => (string) $order->discount_amount,
                'tax_amount' => (string) $order->tax_amount,
                'total' => (string) $order->total,
            ])->save();

            $this->postIssue($invoice->refresh(), $actor);

            return $invoice->refresh();
        });
    }

    public function recordPayment(
        Invoice $invoice,
        string $amount,
        ?BankAccount $bank = null,
        ?User $actor = null,
    ): Invoice {
        $money = Money::of($amount, $invoice->currency);

        if ($money->isZero() || $money->isNegative()) {
            throw new RuntimeException('A payment must be a positive amount.');
        }

        return DB::transaction(function () use ($invoice, $money, $bank, $actor): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($locked->status === 'void') {
                throw new RuntimeException("Invoice {$locked->invoice_number} is void and cannot take a payment.");
            }

            $outstanding = $this->outstanding($locked);

            if ($money->greaterThan($outstanding)) {
                throw new RuntimeException(
                    "This invoice has {$outstanding->format()} outstanding and {$money->format()} was offered. ".
                    'Record the difference as a separate credit rather than overpaying.'
                );
            }

            $paid = Money::of((string) $locked->paid_amount, $locked->currency)->plus($money);

            $locked->forceFill([
                'paid_amount' => $paid->toDecimal(),
                'status' => $paid->equals(Money::of((string) $locked->total, $locked->currency)) ? 'paid' : 'partially_paid',
            ])->save();

            $entry = $this->ledger->post(
                "Payment received against {$locked->invoice_number}",
                [
                    ['account' => AccountCode::Bank, 'debit' => $money->toDecimal(), 'memo' => 'Cash received'],
                    ['account' => AccountCode::AccountsReceivable, 'credit' => $money->toDecimal(), 'memo' => $locked->invoice_number],
                ],
                $locked,
                $actor,
                $locked->currency,
            );

            CashFlow::create([
                'bank_account_id' => $bank?->getKey(),
                'journal_entry_id' => $entry->getKey(),
                'recorded_by' => $actor?->getKey(),
                'direction' => 'in',
                'category' => 'sales',
                'description' => "Payment for {$locked->invoice_number}",
                'currency' => $locked->currency,
                'amount' => $money->toDecimal(),
                'source_type' => Invoice::class,
                'source_id' => $locked->getKey(),
                'occurred_on' => now()->toDateString(),
            ]);

            return $locked->refresh();
        });
    }

    public function void(Invoice $invoice, string $reason, ?User $actor = null): Invoice
    {
        if (! Money::of((string) $invoice->paid_amount, $invoice->currency)->isZero()) {
            throw new RuntimeException(
                'This invoice has payments against it. Issue a credit note rather than voiding it.'
            );
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            $net = Money::of((string) $locked->total, $locked->currency)
                ->minus(Money::of((string) $locked->tax_amount, $locked->currency));

            $this->ledger->post(
                "Void {$locked->invoice_number}: {$reason}",
                [
                    ['account' => AccountCode::Sales, 'debit' => $net->toDecimal(), 'memo' => $reason],
                    ['account' => AccountCode::TaxPayable, 'debit' => (string) $locked->tax_amount, 'memo' => $reason],
                    ['account' => AccountCode::AccountsReceivable, 'credit' => (string) $locked->total, 'memo' => $locked->invoice_number],
                ],
                $locked,
                $actor,
                $locked->currency,
            );

            $locked->forceFill(['status' => 'void', 'voided_at' => now()])->save();

            return $locked->refresh();
        });
    }

    public function outstanding(Invoice $invoice): Money
    {
        return Money::of((string) $invoice->total, $invoice->currency)
            ->minus(Money::of((string) $invoice->paid_amount, $invoice->currency));
    }

    private function postIssue(Invoice $invoice, ?User $actor): void
    {
        $net = Money::of((string) $invoice->total, $invoice->currency)
            ->minus(Money::of((string) $invoice->tax_amount, $invoice->currency));

        $lines = [
            ['account' => AccountCode::AccountsReceivable, 'debit' => (string) $invoice->total, 'memo' => $invoice->invoice_number],
            ['account' => AccountCode::Sales, 'credit' => $net->toDecimal(), 'memo' => 'Revenue'],
        ];

        if (! Money::of((string) $invoice->tax_amount, $invoice->currency)->isZero()) {
            $lines[] = ['account' => AccountCode::TaxPayable, 'credit' => (string) $invoice->tax_amount, 'memo' => 'Tax collected'];
        }

        $this->ledger->post(
            "Invoice {$invoice->invoice_number} issued",
            $lines,
            $invoice,
            $actor,
            $invoice->currency,
        );
    }
}
