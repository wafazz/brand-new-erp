import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
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
    name: string
    phone: string | null
    email: string | null
    status: string
    stage: string | null
    assignee: string | null
    estimated_value: string
    captured_at: string | null
    converted_order_id: string | null
}

interface Props {
    leads: Paginated<Row>
    filters: { q: string; status: string }
    statuses: { value: string; label: string }[]
}

const tone = (status: string) => (status === 'won' ? 'success' : status === 'lost' ? 'danger' : status === 'new' ? 'neutral' : 'info')

export default function LeadIndex({ leads, filters, statuses }: Props) {
    const { can, company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const columns: Column<Row>[] = [
        {
            key: 'lead',
            header: 'Lead',
            render: (row) => (
                <div>
                    <Link href={`/leads/${row.id}`} className="fw-semibold text-decoration-none">{row.name}</Link>
                    <div className="small text-body-secondary font-monospace">{row.reference ?? '—'}</div>
                </div>
            ),
        },
        { key: 'contact', header: 'Contact', render: (row) => row.phone ?? row.email ?? '—' },
        { key: 'stage', header: 'Stage', render: (row) => row.stage ?? '—' },
        { key: 'assignee', header: 'Owner', render: (row) => row.assignee ?? 'Unassigned' },
        { key: 'value', header: 'Est. value', align: 'end', render: (row) => <MoneyText amount={row.estimated_value} currency={currency} /> },
        { key: 'captured', header: 'Captured', render: (row) => row.captured_at ?? '—' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <>
                    <StatusBadge label={row.status} tone={tone(row.status)} />
                    {row.converted_order_id ? <span className="ms-1 small text-body-secondary">converted</span> : null}
                </>
            ),
        },
    ]

    return (
        <AppLayout>
            <Head title="Leads" />

            <PageHeader
                title="Leads"
                subtitle="Where the business came from, before it became an order."
                actions={can('leads.create') ? <Link href="/leads/create" className="btn btn-sm btn-primary">New lead</Link> : null}
            />

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <SearchBar action="/leads" initial={filters.q} placeholder="Name, reference or phone…" />
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '12rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/leads', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true, replace: true })}
                    >
                        <option value="">Any status</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>{status.label}</option>
                        ))}
                    </select>
                </div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={leads.data} rowKey={(row) => row.id} emptyTitle="No leads" />
                </div>
                <div className="card-footer bg-body"><Pagination meta={leads} /></div>
            </div>
        </AppLayout>
    )
}
