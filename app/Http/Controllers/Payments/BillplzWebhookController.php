<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\BillplzSignature;
use App\Domain\Payments\PaymentLinkService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class BillplzWebhookController extends Controller
{
    public function __construct(
        private readonly BillplzSignature $signature,
        private readonly PaymentLinkService $links,
    ) {}

    public function callback(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        if (! $this->signature->validCallback($payload)) {
            abort(403);
        }

        $this->links->settle($payload);

        return response('ok');
    }

    public function return(Request $request): RedirectResponse
    {
        /** @var array<string, mixed> $params */
        $params = $request->query();

        if (! $this->signature->validRedirect($params)) {
            return redirect('/login')->with('error', 'That payment confirmation could not be verified.');
        }

        $paid = ($params['billplz']['paid'] ?? null) === 'true';

        return redirect('/login')->with(
            $paid ? 'success' : 'error',
            $paid
                ? 'Payment received. Thank you — your receipt will follow by email.'
                : 'That payment did not complete. Nothing has been charged.'
        );
    }
}
