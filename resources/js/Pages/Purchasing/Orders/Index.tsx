import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import ExportButton from '@/Components/ExportButton'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    reference: string | null
    status: string
    supplier: string | null
    branch: string | null
    currency: string
    total: string
    expected_at: string | null
}

interface Props {
    orders: Paginated<Row>
    filters: { q: string; status: string }
    statuses: { value: string; label: string }[]
}

export const orderTone = (status: string) =>
    status === 'received' || status === 'closed' || status === 'billed'
        ? 'success'
        : status === 'cancelled'
            ? 'danger'
            : status === 'pending'
                ? 'warning'
                : status === 'approved' || status === 'partially_received'
                    ? 'info'
                    : 'neutral'

export default function PurchaseOrderIndex({ orders, filters, statuses }: Props) {
    const { can } = useAuth()

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: 'Order',
            render: (row) => (
                <div>
                    <Link href={`/purchase-orders/${row.id}`} className="fw-semibold text-decoration-none font-monospace">
                        {row.reference ?? '—'}
                    </Link>
                    {row.branch ? <div className="small text-body-secondary">{row.branch}</div> : null}
                </div>
            ),
        },
        { key: 'supplier', header: 'Supplier', render: (row) => row.supplier ?? '—' },
        { key: 'expected', header: 'Expected', render: (row) => row.expected_at ?? '—' },
        { key: 'total', header: 'Total', align: 'end', render: (row) => <MoneyText amount={row.total} currency={row.currency} /> },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.status.replace(/_/g, ' ')} tone={orderTone(row.status)} />,
        },
    ]

    return (
        <AppLayout>
            <Head title="Purchase orders" />

            <PageHeader
                title="Purchase orders"
                subtitle="What you have committed to buy, and how much of it has arrived."
                actions={
                    <>
                        <ExportButton exportKey="purchase-orders" ability="purchasing.export" />
                        {can('purchasing.create') ? <Link href="/purchase-orders/create" className="btn btn-sm btn-primary">New order</Link> : null}
                    </>
                }
            />

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <SearchBar action="/purchase-orders" initial={filters.q} placeholder="Reference…" />
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '13rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/purchase-orders', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true, replace: true })}
                    >
                        <option value="">Any status</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>{status.label}</option>
                        ))}
                    </select>
                </div>
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={orders.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No purchase orders"
                        emptyDescription="Nothing raised yet, or nothing within your branch scope."
                    />
                </div>
                <div className="card-footer bg-body"><Pagination meta={orders} /></div>
            </div>
        </AppLayout>
    )
}
