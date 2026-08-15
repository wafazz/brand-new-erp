import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import StatCard from '@/Components/StatCard'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    invoice_number: string
    customer_name: string
    status: string
    currency: string
    total: string
    paid_amount: string
    outstanding: string
    issued_at: string | null
    due_at: string | null
    overdue: boolean
}

interface Props {
    invoices: Paginated<Row>
    filters: { q: string; status: string }
    ageing: Record<string, string> | null
}

const statusTone = (status: string) =>
    status === 'paid' ? 'success' : status === 'void' ? 'danger' : status === 'partially_paid' ? 'warning' : 'neutral'

export default function InvoiceIndex({ invoices, filters, ageing }: Props) {
    const columns: Column<Row>[] = [
        {
            key: 'invoice',
            header: 'Invoice',
            render: (row) => (
                <div>
                    <Link href={`/invoices/${row.id}`} className="fw-semibold text-decoration-none font-monospace">{row.invoice_number}</Link>
                    <div className="small text-body-secondary">{row.customer_name}</div>
                </div>
            ),
        },
        { key: 'issued', header: 'Issued', render: (row) => row.issued_at ?? '—' },
        {
            key: 'due',
            header: 'Due',
            render: (row) => (
                <span className={row.overdue ? 'text-danger fw-semibold' : ''}>
                    {row.due_at ?? '—'}{row.overdue ? ' (overdue)' : ''}
                </span>
            ),
        },
        { key: 'total', header: 'Total', align: 'end', render: (row) => <MoneyText amount={row.total} currency={row.currency} /> },
        { key: 'paid', header: 'Paid', align: 'end', render: (row) => <MoneyText amount={row.paid_amount} currency={row.currency} muted /> },
        { key: 'outstanding', header: 'Outstanding', align: 'end', render: (row) => <MoneyText amount={row.outstanding} currency={row.currency} /> },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status.replace('_', ' ')} tone={statusTone(row.status)} /> },
    ]

    return (
        <AppLayout>
            <Head title="Invoices" />

            <PageHeader title="Invoices" subtitle="What has been billed, what has been collected, and what is late." />

            {ageing ? (
                <div className="row g-3 mb-4">
                    {Object.entries(ageing).map(([bucket, amount]) => (
                        <div key={bucket} className="col-6 col-lg">
                            <StatCard
                                label={bucket.replace('_', ' ')}
                                value={Number(amount).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                tone={bucket === 'current' ? 'default' : Number(amount) > 0 ? 'warning' : 'default'}
                            />
                        </div>
                    ))}
                </div>
            ) : null}

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <SearchBar action="/invoices" initial={filters.q} placeholder="Invoice number or customer…" />
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '12rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/invoices', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true, replace: true })}
                    >
                        <option value="">Any status</option>
                        {['issued', 'partially_paid', 'paid', 'void'].map((status) => (
                            <option key={status} value={status}>{status.replace('_', ' ')}</option>
                        ))}
                    </select>
                </div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={invoices.data} rowKey={(row) => row.id} emptyTitle="No invoices" />
                </div>
                <div className="card-footer bg-body"><Pagination meta={invoices} /></div>
            </div>
        </AppLayout>
    )
}
