import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    reference: string | null
    status: string
    requester: string | null
    branch: string | null
    items_count: number
    needed_by: string | null
    created_at: string | null
}

interface Props {
    requests: Paginated<Row>
    filters: { q: string; status: string }
    statuses: { value: string; label: string }[]
}

export const statusTone = (status: string) =>
    status === 'approved' || status === 'ordered'
        ? 'success'
        : status === 'rejected' || status === 'cancelled'
            ? 'danger'
            : status === 'pending'
                ? 'warning'
                : 'neutral'

export default function PurchaseRequestIndex({ requests, filters, statuses }: Props) {
    const { can } = useAuth()

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: 'Request',
            render: (row) => (
                <div>
                    <Link href={`/purchase-requests/${row.id}`} className="fw-semibold text-decoration-none font-monospace">
                        {row.reference ?? '—'}
                    </Link>
                    <div className="small text-body-secondary">{row.created_at ?? ''}</div>
                </div>
            ),
        },
        { key: 'requester', header: 'Raised by', render: (row) => row.requester ?? '—' },
        { key: 'branch', header: 'Branch', render: (row) => row.branch ?? '—' },
        { key: 'items', header: 'Lines', align: 'end', render: (row) => String(row.items_count) },
        { key: 'needed', header: 'Needed by', render: (row) => row.needed_by ?? '—' },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status} tone={statusTone(row.status)} /> },
    ]

    return (
        <AppLayout>
            <Head title="Purchase requests" />

            <PageHeader
                title="Purchase requests"
                subtitle="What someone has asked to buy, before anyone has committed money to it."
                actions={can('purchasing.create') ? <Link href="/purchase-requests/create" className="btn btn-sm btn-primary">New request</Link> : null}
            />

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <SearchBar action="/purchase-requests" initial={filters.q} placeholder="Reference…" />
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '12rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/purchase-requests', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true, replace: true })}
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
                        rows={requests.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No purchase requests"
                        emptyDescription="Nothing raised yet, or nothing within your branch scope."
                    />
                </div>
                <div className="card-footer bg-body"><Pagination meta={requests} /></div>
            </div>
        </AppLayout>
    )
}
