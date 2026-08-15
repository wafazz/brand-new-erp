<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ErasureService
{
    public const REDACTED = '[erased]';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function eraseCustomer(Customer $customer, string $reason, ?User $actor = null): ErasureReport
    {
        if (trim($reason) === '') {
            throw new RuntimeException('An erasure requires a stated reason. Erasure without a record is not erasure.');
        }

        return DB::transaction(function () use ($customer, $reason, $actor): ErasureReport {
            $label = $customer->code;

            $contacts = CustomerContact::query()->where('customer_id', $customer->getKey())->get();

            foreach ($contacts as $contact) {
                $contact->forceFill([
                    'name' => self::REDACTED,
                    'email' => null,
                    'phone' => null,
                ])->save();
            }

            $addresses = CustomerAddress::query()->where('customer_id', $customer->getKey())->get();

            foreach ($addresses as $address) {
                $address->forceFill([
                    'line1' => self::REDACTED,
                    'line2' => null,
                    'postcode' => null,
                ])->save();
            }

            $leads = Lead::query()->where('converted_customer_id', $customer->getKey())->get();

            foreach ($leads as $lead) {
                $lead->forceFill([
                    'name' => self::REDACTED,
                    'phone' => null,
                    'email' => null,
                    'note' => null,
                ])->save();
            }

            $orders = Order::query()->where('customer_id', $customer->getKey())->get();

            foreach ($orders as $order) {
                $order->forceFill([
                    'customer_name' => self::REDACTED,
                    'customer_phone' => null,
                    'customer_email' => null,
                    'ship_line1' => self::REDACTED,
                    'ship_line2' => null,
                    'ship_postcode' => null,
                ])->save();
            }

            $invoices = Invoice::query()->where('customer_id', $customer->getKey())->get();

            $customer->forceFill([
                'name' => self::REDACTED,
                'company_name' => null,
                'email' => null,
                'phone' => null,
                'tax_no' => null,
                'notes' => null,
                'status' => 'inactive',
            ])->save();

            $report = new ErasureReport(
                subject: "customer {$label}",
                anonymised: [
                    'customer records' => 1,
                    'contacts' => $contacts->count(),
                    'addresses' => $addresses->count(),
                    'leads' => $leads->count(),
                    'orders' => $orders->count(),
                ],
                retained: ['invoices' => $invoices->count()],
                reason: $reason,
            );

            $this->audit->record('personal_data_erased', 'privacy', $customer, $actor, $report->explain());

            return $report;
        });
    }

    /** @return array<string, int> */
    public function residualPersonalData(Customer $customer): array
    {
        return [
            'orders_with_name' => Order::query()
                ->where('customer_id', $customer->getKey())
                ->whereNot('customer_name', self::REDACTED)
                ->count(),
            'leads_with_name' => Lead::query()
                ->where('converted_customer_id', $customer->getKey())
                ->whereNot('name', self::REDACTED)
                ->count(),
            'contacts_with_name' => CustomerContact::query()
                ->where('customer_id', $customer->getKey())
                ->whereNot('name', self::REDACTED)
                ->count(),
        ];
    }
}
