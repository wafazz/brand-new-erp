<?php

declare(strict_types=1);

namespace App\Domain\Exporting;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Stock;
use App\Models\SupplierBill;
use App\Models\User;

class ExportRegistry
{
    /** @return array<string, ExportDefinition> */
    public function all(): array
    {
        $definitions = [
            new ExportDefinition(
                key: 'customers',
                label: 'Customers',
                ability: 'customers.export',
                scopeAbility: 'customers.view',
                model: Customer::class,
                columns: [
                    'Code' => fn (Customer $r): string => (string) $r->code,
                    'Name' => fn (Customer $r): string => (string) $r->name,
                    'Type' => fn (Customer $r): string => (string) $r->type,
                    'Company' => fn (Customer $r): string => (string) $r->company_name,
                    'Email' => fn (Customer $r): string => (string) $r->email,
                    'Phone' => fn (Customer $r): string => (string) $r->phone,
                    'Tax number' => fn (Customer $r): string => (string) $r->tax_no,
                    'Status' => fn (Customer $r): string => (string) $r->status,
                    'Currency' => fn (Customer $r): string => (string) $r->currency,
                    'Credit limit' => fn (Customer $r): string => (string) $r->credit_limit,
                    'Payment terms (days)' => fn (Customer $r): string => (string) $r->payment_terms_days,
                    'Created' => fn (Customer $r): string => (string) $r->created_at?->toDateString(),
                ],
                orderBy: 'name',
                direction: 'asc',
            ),
            new ExportDefinition(
                key: 'orders',
                label: 'Orders',
                ability: 'orders.export',
                scopeAbility: 'orders.view',
                model: Order::class,
                columns: [
                    'Order number' => fn (Order $r): string => (string) $r->order_number,
                    'Placed' => fn (Order $r): string => (string) $r->placed_at?->toDateString(),
                    'Customer' => fn (Order $r): string => (string) ($r->customer->name ?? $r->customer_name),
                    'Payment status' => fn (Order $r): string => (string) $r->payment_status->value,
                    'Fulfilment status' => fn (Order $r): string => (string) $r->fulfilment_status->value,
                    'Exception' => fn (Order $r): string => (string) $r->exception_status->value,
                    'Currency' => fn (Order $r): string => (string) $r->currency,
                    'Subtotal' => fn (Order $r): string => (string) $r->subtotal,
                    'Discount' => fn (Order $r): string => (string) $r->discount_amount,
                    'Tax' => fn (Order $r): string => (string) $r->tax_amount,
                    'Total' => fn (Order $r): string => (string) $r->total,
                    'Paid' => fn (Order $r): string => (string) $r->paid_amount,
                    'Salesperson' => fn (Order $r): string => (string) ($r->owner->name ?? ''),
                ],
                with: ['customer:id,name', 'owner:id,name'],
                orderBy: 'placed_at',
            ),
            new ExportDefinition(
                key: 'invoices',
                label: 'Invoices',
                ability: 'invoices.export',
                scopeAbility: 'invoices.view',
                model: Invoice::class,
                columns: [
                    'Invoice number' => fn (Invoice $r): string => (string) $r->invoice_number,
                    'Issued' => fn (Invoice $r): string => (string) $r->issued_at?->toDateString(),
                    'Due' => fn (Invoice $r): string => (string) $r->due_at?->toDateString(),
                    'Customer' => fn (Invoice $r): string => (string) $r->customer_name,
                    'Tax number' => fn (Invoice $r): string => (string) $r->customer_tax_no,
                    'Status' => fn (Invoice $r): string => (string) $r->status,
                    'Currency' => fn (Invoice $r): string => (string) $r->currency,
                    'Subtotal' => fn (Invoice $r): string => (string) $r->subtotal,
                    'Tax' => fn (Invoice $r): string => (string) $r->tax_amount,
                    'Total' => fn (Invoice $r): string => (string) $r->total,
                    'Paid' => fn (Invoice $r): string => (string) $r->paid_amount,
                    'Outstanding' => fn (Invoice $r): string => bcsub((string) $r->total, (string) $r->paid_amount, 4),
                    'Order' => fn (Invoice $r): string => (string) ($r->order->order_number ?? ''),
                ],
                with: ['order:id,order_number'],
                orderBy: 'issued_at',
            ),
            new ExportDefinition(
                key: 'leads',
                label: 'Leads',
                ability: 'leads.export',
                scopeAbility: 'leads.view',
                model: Lead::class,
                columns: [
                    'Reference' => fn (Lead $r): string => (string) $r->reference,
                    'Name' => fn (Lead $r): string => (string) $r->name,
                    'Email' => fn (Lead $r): string => (string) $r->email,
                    'Phone' => fn (Lead $r): string => (string) $r->phone,
                    'Status' => fn (Lead $r): string => (string) $r->status,
                    'Stage' => fn (Lead $r): string => (string) ($r->stage->name ?? ''),
                    'Estimated value' => fn (Lead $r): string => (string) $r->estimated_value,
                    'Assigned to' => fn (Lead $r): string => (string) ($r->assignee->name ?? ''),
                    'Created' => fn (Lead $r): string => (string) $r->created_at?->toDateString(),
                ],
                with: ['assignee:id,name', 'stage:id,name'],
            ),
            new ExportDefinition(
                key: 'commissions',
                label: 'Commission',
                ability: 'commissions.export',
                scopeAbility: 'commissions.view',
                model: Commission::class,
                columns: [
                    'Period' => fn (Commission $r): string => (string) $r->period,
                    'Recipient' => fn (Commission $r): string => (string) ($r->recipient->name ?? ''),
                    'Recipient role' => fn (Commission $r): string => (string) $r->recipient_role,
                    'Order' => fn (Commission $r): string => (string) ($r->order->order_number ?? ''),
                    'Basis' => fn (Commission $r): string => (string) $r->basis_amount,
                    'Rate type' => fn (Commission $r): string => (string) $r->rate_type,
                    'Rate applied' => fn (Commission $r): string => (string) $r->rate_applied,
                    'Amount' => fn (Commission $r): string => (string) $r->amount,
                    'Currency' => fn (Commission $r): string => (string) $r->currency,
                    'Status' => fn (Commission $r): string => (string) $r->status,
                    'Provisional' => fn (Commission $r): string => $r->is_provisional ? 'yes' : 'no',
                ],
                with: ['recipient:id,name', 'order:id,order_number'],
                orderBy: 'period',
            ),
            new ExportDefinition(
                key: 'purchase-orders',
                label: 'Purchase orders',
                ability: 'purchasing.export',
                scopeAbility: 'purchasing.view',
                model: PurchaseOrder::class,
                columns: [
                    'Reference' => fn (PurchaseOrder $r): string => (string) $r->reference,
                    'Supplier' => fn (PurchaseOrder $r): string => (string) ($r->supplier->name ?? ''),
                    'Status' => fn (PurchaseOrder $r): string => (string) $r->status,
                    'Expected' => fn (PurchaseOrder $r): string => (string) $r->expected_at?->toDateString(),
                    'Currency' => fn (PurchaseOrder $r): string => (string) $r->currency,
                    'Subtotal' => fn (PurchaseOrder $r): string => (string) $r->subtotal,
                    'Tax' => fn (PurchaseOrder $r): string => (string) $r->tax_amount,
                    'Total' => fn (PurchaseOrder $r): string => (string) $r->total,
                ],
                with: ['supplier:id,name'],
            ),
            new ExportDefinition(
                key: 'supplier-bills',
                label: 'Supplier bills',
                ability: 'purchasing.export',
                scopeAbility: null,
                model: SupplierBill::class,
                columns: [
                    'Reference' => fn (SupplierBill $r): string => (string) $r->reference,
                    'Supplier invoice number' => fn (SupplierBill $r): string => (string) $r->supplier_invoice_number,
                    'Supplier' => fn (SupplierBill $r): string => (string) ($r->supplier->name ?? ''),
                    'Status' => fn (SupplierBill $r): string => (string) $r->status,
                    'Billed' => fn (SupplierBill $r): string => (string) $r->billed_at?->toDateString(),
                    'Due' => fn (SupplierBill $r): string => (string) $r->due_at?->toDateString(),
                    'Currency' => fn (SupplierBill $r): string => (string) $r->currency,
                    'Total' => fn (SupplierBill $r): string => (string) $r->total,
                    'Paid' => fn (SupplierBill $r): string => (string) $r->paid_amount,
                ],
                with: ['supplier:id,name'],
            ),
            new ExportDefinition(
                key: 'inventory',
                label: 'Stock on hand',
                ability: 'inventory.export',
                scopeAbility: 'inventory.view',
                model: Stock::class,
                columns: [
                    'SKU' => fn (Stock $r): string => (string) ($r->variant->sku ?? ''),
                    'Variant' => fn (Stock $r): string => (string) ($r->variant->name ?? ''),
                    'Warehouse' => fn (Stock $r): string => (string) ($r->warehouse->name ?? ''),
                    'On hand' => fn (Stock $r): string => (string) $r->on_hand,
                    'Reserved' => fn (Stock $r): string => (string) $r->reserved,
                    'Available' => fn (Stock $r): string => bcsub((string) $r->on_hand, (string) $r->reserved, 4),
                    'Incoming' => fn (Stock $r): string => (string) $r->incoming,
                    'Low stock threshold' => fn (Stock $r): string => (string) $r->low_stock_threshold,
                ],
                with: ['variant:id,sku,name', 'warehouse:id,name'],
                orderBy: 'updated_at',
            ),
            new ExportDefinition(
                key: 'products',
                label: 'Products and variants',
                ability: 'products.export',
                scopeAbility: null,
                model: ProductVariant::class,
                columns: [
                    'SKU' => fn (ProductVariant $r): string => (string) $r->sku,
                    'Product' => fn (ProductVariant $r): string => (string) ($r->product->name ?? ''),
                    'Variant' => fn (ProductVariant $r): string => (string) $r->name,
                    'Barcode' => fn (ProductVariant $r): string => (string) $r->barcode,
                    'Selling price' => fn (ProductVariant $r): string => (string) $r->selling_price,
                    'Cost price' => fn (ProductVariant $r): string => (string) $r->cost_price,
                    'Weight (g)' => fn (ProductVariant $r): string => (string) $r->weight_grams,
                    'Default' => fn (ProductVariant $r): string => $r->is_default ? 'yes' : 'no',
                ],
                with: ['product:id,name'],
                orderBy: 'sku',
                direction: 'asc',
            ),
            new ExportDefinition(
                key: 'audit',
                label: 'Audit log',
                ability: 'audit.export',
                scopeAbility: 'audit.view',
                model: AuditLog::class,
                columns: [
                    'When' => fn (AuditLog $r): string => (string) $r->created_at?->toDateTimeString(),
                    'Actor' => fn (AuditLog $r): string => (string) ($r->actor->name ?? ''),
                    'Action' => fn (AuditLog $r): string => (string) $r->action,
                    'Module' => fn (AuditLog $r): string => (string) $r->module,
                    'Record type' => fn (AuditLog $r): string => (string) $r->auditable_type,
                    'Record' => fn (AuditLog $r): string => (string) $r->auditable_id,
                    'Reason' => fn (AuditLog $r): string => (string) $r->reason,
                    'IP' => fn (AuditLog $r): string => (string) $r->ip_address,
                ],
                with: ['actor:id,name'],
            ),
        ];

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }

    public function find(string $key): ?ExportDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<int, array{key: string, label: string}> */
    public function availableTo(User $user): array
    {
        $available = [];

        foreach ($this->all() as $definition) {
            if ($user->can($definition->ability)) {
                $available[] = ['key' => $definition->key, 'label' => $definition->label];
            }
        }

        return $available;
    }
}
