import { Head, Link } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

interface CustomerRow {
    id: string
    code: string
    name: string
    type: string
    phone: string | null
    email: string | null
    group: string | null
    status: string
    credit_limit: string
}

interface Props {
    customers: Paginated<CustomerRow>
    filters: { q: string }
}

export default function CustomerIndex({ customers, filters }: Props) {
    const { can } = useAuth()

    const columns: Column<CustomerRow>[] = [
        {
            key: 'name',
            header: 'Customer',
            render: (row) => (
                <div>
                    <Link href={`/customers/${row.id}`} className="fw-semibold text-decoration-none">
                        {row.name}
                    </Link>
                    <div className="small text-body-secondary font-monospace">{row.code}</div>
                </div>
            ),
        },
        { key: 'type', header: 'Type', render: (row) => (row.type === 'business' ? 'Business' : 'Individual') },
        {
            key: 'contact',
            header: 'Contact',
            render: (row) => (
                <div className="small">
                    <div>{row.phone ?? '—'}</div>
                    <div className="text-body-secondary">{row.email ?? ''}</div>
                </div>
            ),
        },
        { key: 'group', header: 'Group', render: (row) => row.group ?? '—' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <StatusBadge
                    label={row.status}
                    tone={row.status === 'active' ? 'success' : row.status === 'blocked' ? 'danger' : 'neutral'}
                />
            ),
        },
    ]

    return (
        <AppLayout>
            <Head title="Customers" />

            <PageHeader
                title="Customers"
                subtitle="Only the customers your role and data scope permit."
                actions={
                    <>
                        <SearchBar action="/customers" initial={filters.q} placeholder="Name, code or phone" />
                        {can('customers.create') ? (
                            <Link href="/customers/create" className="btn btn-sm btn-primary text-nowrap">
                                <i className="bi bi-plus-lg me-1" aria-hidden="true" />
                                New customer
                            </Link>
                        ) : null}
                    </>
                }
            />

            <div className="card">
                <div className="card-body">
                    <DataTable
                        columns={columns}
                        rows={customers.data}
                        rowKey={(row) => row.id}
                        emptyTitle={filters.q === '' ? 'No customers yet' : `Nothing matches “${filters.q}”`}
                        emptyDescription={filters.q === '' ? 'Create the first one to get started.' : undefined}
                    />
                </div>
                <div className="card-footer bg-body">
                    <Pagination meta={customers} />
                </div>
            </div>
        </AppLayout>
    )
}
