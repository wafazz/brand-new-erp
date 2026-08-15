import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import ExportButton from '@/Components/ExportButton'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    reference: string | null
    supplier_invoice_number: string | null
    supplier: string | null
    purchase_order: string | null
    status: string
    currency: string
    total: string
    paid_amount: string
    outstanding: string
    billed_at: string | null
    due_at: string | null
}

interface Props {
    bills: Paginated<Row>
    filters: { q: string; status: string }
    statuses: { value: string; label: string }[]
}

export const billTone = (status: string) =>
    status === 'paid' ? 'success' : status === 'disputed' || status === 'cancelled' ? 'danger' : status === 'approved' ? 'info' : 'neutral'

export default function SupplierBillIndex({ bills, filters, statuses }: Props) {
    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: 'Bill',
            render: (row) => (
                <div>
                    <Link href={`/supplier-bills/${row.id}`} className="fw-semibold text-decoration-none font-monospace">
                        {row.reference ?? '—'}
                    </Link>
                    <div className="small text-body-secondary">{row.supplier_invoice_number ?? 'No supplier invoice number'}</div>
                </div>
            ),
        },
        { key: 'supplier', header: 'Supplier', render: (row) => row.supplier ?? '—' },
        { key: 'order', header: 'Order', render: (row) => <span className="font-monospace small">{row.purchase_order ?? '—'}</span> },
        { key: 'due', header: 'Due', render: (row) => row.due_at ?? '—' },
        { key: 'total', header: 'Total', align: 'end', render: (row) => <MoneyText amount={row.total} currency={row.currency} /> },
        {
            key: 'outstanding',
            header: 'Outstanding',
            align: 'end',
            render: (row) =>
                Number(row.outstanding) === 0
                    ? <span className="text-body-secondary">settled</span>
                    : <MoneyText amount={row.outstanding} currency={row.currency} />,
        },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status} tone={billTone(row.status)} /> },
    ]

    return (
        <AppLayout>
            <Head title="Supplier bills" />

            <PageHeader
                title="Supplier bills"
                subtitle="What the supplier says you owe — checked against the order and what actually arrived."
                actions={<ExportButton exportKey="supplier-bills" ability="purchasing.export" />}
            />

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <SearchBar action="/supplier-bills" initial={filters.q} placeholder="Reference or supplier invoice…" />
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '12rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/supplier-bills', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true, replace: true })}
                    >
                        <option value="">Any status</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>{status.label}</option>
                        ))}
                    </select>
                </div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={bills.data} rowKey={(row) => row.id} emptyTitle="No bills recorded" />
                </div>
                <div className="card-footer bg-body"><Pagination meta={bills} /></div>
            </div>
        </AppLayout>
    )
}
