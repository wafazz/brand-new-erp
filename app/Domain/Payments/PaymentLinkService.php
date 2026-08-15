<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Finance\InvoiceService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentLinkService
{
    public function __construct(
        private readonly BillplzClient $client,
        private readonly InvoiceService $invoices,
        private readonly CompanyContext $companies,
    ) {}

    public function createFor(Invoice $invoice, ?User $actor = null): PaymentIntent
    {
        if ($invoice->status === 'void') {
            throw new RuntimeException("Invoice {$invoice->invoice_number} is void.");
        }

        if ($invoice->currency !== 'MYR') {
            throw new RuntimeException('Billplz settles in MYR only; this invoice is in '.$invoice->currency.'.');
        }

        $outstanding = $this->invoices->outstanding($invoice);

        if ($outstanding->isZero() || $outstanding->isNegative()) {
            throw new RuntimeException("Invoice {$invoice->invoice_number} has nothing outstanding.");
        }

        $cents = (int) round(((float) $outstanding->toDecimal()) * 100);

        if ($cents < 100) {
            throw new RuntimeException('Billplz cannot collect less than RM1.00.');
        }

        $existing = $this->reusable($invoice, $outstanding);

        if ($existing instanceof PaymentIntent) {
            return $existing;
        }

        $invoice->loadMissing('customer');

        $customer = $invoice->customer;
        $payerEmail = $customer instanceof Customer ? ($customer->email ?: null) : null;
        $payerMobile = $customer instanceof Customer ? ($customer->phone ?: null) : null;
        $payerName = $invoice->customer_name ?: ($customer instanceof Customer ? $customer->name : 'Customer');

        $intent = PaymentIntent::create([
            'invoice_id' => $invoice->getKey(),
            'requested_by' => $actor?->getKey(),
            'amount' => $outstanding->toDecimal(),
            'currency' => 'MYR',
        ]);

        $bill = $this->client->createBill([
            'email' => $payerEmail,
            'mobile' => $payerEmail === null ? $payerMobile : null,
            'name' => mb_substr($payerName, 0, 255),
            'amount' => $cents,
            'description' => mb_substr("Invoice {$invoice->invoice_number}", 0, 200),
            'callback_url' => route('billplz.callback'),
            'redirect_url' => route('billplz.return'),
            'reference_1_label' => 'Invoice',
            'reference_1' => $invoice->invoice_number,
        ]);

        $intent->forceFill(['provider_ref' => $bill['id'], 'pay_url' => $bill['url']])->save();

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function settle(array $payload): bool
    {
        $ref = $payload['id'] ?? null;

        if (! is_string($ref) || $ref === '') {
            return false;
        }

        $paid = in_array($payload['paid'] ?? null, [true, 'true', '1'], true);

        $intent = PaymentIntent::acrossCompanies()
            ->where('provider', 'billplz')
            ->where('provider_ref', $ref)
            ->first();

        if (! $intent instanceof PaymentIntent) {
            Log::warning('Billplz callback for an unknown bill.', ['bill' => $ref]);

            return false;
        }

        return $this->companies->runAs(
            $intent->company_id,
            fn (): bool => $this->apply($intent->getKey(), $payload, $paid)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(string $intentId, array $payload, bool $paid): bool
    {
        return DB::transaction(function () use ($intentId, $payload, $paid): bool {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intentId);

            $locked->forceFill(['last_callback' => $payload])->save();

            if ($locked->status === 'paid') {
                return true;
            }

            if (! $paid) {
                return false;
            }

            $invoice = Invoice::query()->findOrFail($locked->invoice_id);

            $amount = Money::of((string) $locked->amount, 'MYR');
            $claimed = $this->claimedAmount($payload);

            if ($claimed instanceof Money && $amount->greaterThan($claimed)) {
                Log::warning('Billplz reported collecting less than the bill was raised for.', [
                    'intent' => $locked->getKey(),
                    'billed' => $amount->toDecimal(),
                    'claimed' => $claimed->toDecimal(),
                ]);

                $amount = $claimed;
            }

            $outstanding = $this->invoices->outstanding($invoice);

            if ($amount->greaterThan($outstanding)) {
                $amount = $outstanding;
            }

            if ($amount->isZero() || $amount->isNegative()) {
                Log::warning('Billplz reported a payment worth nothing; leaving the intent open.', [
                    'intent' => $locked->getKey(),
                    'amount' => $amount->toDecimal(),
                ]);

                return false;
            }

            $this->invoices->recordPayment($invoice, $amount->toDecimal(), null, null);

            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function claimedAmount(array $payload): ?Money
    {
        $cents = $payload['paid_amount'] ?? null;

        if (! is_numeric($cents)) {
            return null;
        }

        return Money::of(bcdiv((string) (int) $cents, '100', 4), 'MYR');
    }

    private function reusable(Invoice $invoice, Money $outstanding): ?PaymentIntent
    {
        $intent = PaymentIntent::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', 'pending')
            ->whereNotNull('pay_url')
            ->latest()
            ->first();

        if (! $intent instanceof PaymentIntent) {
            return null;
        }

        return Money::of((string) $intent->amount, 'MYR')->equals($outstanding) ? $intent : null;
    }
}
