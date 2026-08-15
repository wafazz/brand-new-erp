<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\AgeingReport;
use App\Domain\Finance\InvoiceService;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AgeingReport $ageing,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $term = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $invoices = Invoice::query()
            ->visibleTo($request->user(), 'invoices.view')
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('invoice_number', 'ilike', "%{$term}%")->orWhere('customer_name', 'ilike', "%{$term}%");
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('issued_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Invoice $invoice): array => [
                'id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer_name,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'total' => (string) $invoice->total,
                'paid_amount' => (string) $invoice->paid_amount,
                'outstanding' => $this->invoices->outstanding($invoice)->toDecimal(),
                'issued_at' => $invoice->issued_at?->toDateString(),
                'due_at' => $invoice->due_at?->toDateString(),
                'overdue' => $invoice->due_at !== null
                    && $invoice->due_at->isPast()
                    && ! $this->invoices->outstanding($invoice)->isZero(),
            ]);

        return Inertia::render('Finance/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => ['q' => $term, 'status' => $status],
            'ageing' => $request->user()?->can('reports.view') === true ? $this->ageing->buckets() : null,
        ]);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        $invoice->loadMissing(['items', 'order:id,order_number', 'customer:id,name']);

        $user = $request->user();

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'customer_name' => $invoice->customer_name,
                'customer_tax_no' => $invoice->customer_tax_no,
                'currency' => $invoice->currency,
                'subtotal' => (string) $invoice->subtotal,
                'discount_amount' => (string) $invoice->discount_amount,
                'tax_amount' => (string) $invoice->tax_amount,
                'total' => (string) $invoice->total,
                'paid_amount' => (string) $invoice->paid_amount,
                'outstanding' => $this->invoices->outstanding($invoice)->toDecimal(),
                'issued_at' => $invoice->issued_at?->toDayDateTimeString(),
                'due_at' => $invoice->due_at?->toDateString(),
                'order_id' => $invoice->order_id,
                'order_number' => $invoice->order?->order_number,
            ],
            'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                'id' => $item->getKey(),
                'sku' => $item->sku,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'tax_amount' => (string) $item->tax_amount,
                'line_total' => (string) $item->line_total,
            ])->all(),
            'bankAccounts' => BankAccount::query()->orderBy('name')->get()
                ->map(fn (BankAccount $b): array => ['value' => $b->getKey(), 'label' => $b->name])->all(),
            'permissions' => [
                'record_payment' => $user?->can('recordPayment', $invoice) ?? false,
                'void' => $user?->can('void', $invoice) ?? false,
            ],
        ]);
    }

    public function issue(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('issueInvoice', $order);

        $data = $request->validate([
            'terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        try {
            $invoice = $this->invoices->issueFromOrder($order, (int) ($data['terms_days'] ?? 30), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect("/invoices/{$invoice->getKey()}")->with('success', "Invoice {$invoice->invoice_number} issued.");
    }

    public function recordPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('recordPayment', $invoice);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'bank_account_id' => ['nullable', 'string', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)],
        ]);

        $bank = isset($data['bank_account_id']) ? BankAccount::query()->find($data['bank_account_id']) : null;

        try {
            $this->invoices->recordPayment($invoice, (string) $data['amount'], $bank, $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Payment recorded.');
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('void', $invoice);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $this->invoices->void($invoice, $data['reason'], $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Invoice voided.');
    }
}
