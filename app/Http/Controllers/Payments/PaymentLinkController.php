<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\PaymentLinkService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentLinkController extends Controller
{
    public function __construct(
        private readonly PaymentLinkService $links,
        private readonly AuditRecorder $recorder,
    ) {}

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('view', $invoice);

        if ($request->user()?->can('payments.create') !== true) {
            abort(403, 'You may not raise a payment link.');
        }

        try {
            $intent = $this->links->createFor($invoice, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->recorder->record('payment_link_created', 'invoices', $invoice, $request->user());

        return back()->with('success', 'Payment link ready.')->with('payUrl', $intent->pay_url);
    }
}
