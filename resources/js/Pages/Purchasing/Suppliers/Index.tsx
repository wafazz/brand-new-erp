import { Head, Link } from '@inertiajs/react'
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
    code: string | null
    name: string
    email: string | null
    phone: string | null
    currency: string
    status: string
    payment_terms_days: number
}

interface Props {
    suppliers: Paginated<Row>
    filters: { q: string }
}

export default function SupplierIndex({ suppliers, filters }: Props) {
    const { can } = useAuth()

    const columns: Column<Row>[] = [
        {
            key: 'supplier',
            header: 'Supplier',
            render: (row) => (
                <div>
                    <Link href={`/suppliers/${row.id}`} className="fw-semibold text-decoration-none">{row.name}</Link>
                    <div className="small text-body-secondary font-monospace">{row.code ?? '—'}</div>
                </div>
            ),
        },
        { key: 'phone', header: 'Phone', render: (row) => row.phone ?? '—' },
        { key: 'email', header: 'Email', render: (row) => row.email ?? '—' },
        { key: 'currency', header: 'Currency', render: (row) => row.currency },
        { key: 'terms', header: 'Terms', align: 'end', render: (row) => `${row.payment_terms_days} days` },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.status} tone={row.status === 'active' ? 'success' : row.status === 'blocked' ? 'danger' : 'neutral'} />,
        },
    ]

    return (
        <AppLayout>
            <Head title="Suppliers" />

            <PageHeader
                title="Suppliers"
                subtitle="Who you buy from, and on what terms."
                actions={can('suppliers.create') ? <Link href="/suppliers/create" className="btn btn-sm btn-primary">New supplier</Link> : null}
            />

            <div className="card">
                <div className="card-header bg-body">
                    <SearchBar action="/suppliers" initial={filters.q} placeholder="Name or code…" />
                </div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={suppliers.data} rowKey={(row) => row.id} emptyTitle="No suppliers yet" />
                </div>
                <div className="card-footer bg-body"><Pagination meta={suppliers} /></div>
            </div>
        </AppLayout>
    )
}
