import { Head, Link } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import type { Paginated } from '@/Types'
import type { RoleOption } from './Form'

interface Row {
    id: string
    user_id: string
    name: string | null
    email: string | null
    role: string | null
    branch: string | null
    employee_no: string | null
    is_active: boolean
    is_self: boolean
}

interface Props {
    members: Paginated<Row>
    filters: { q: string }
    roles: RoleOption[]
    can: { create: boolean }
}

export default function UserIndex({ members, filters, roles, can }: Props) {
    const labelFor = (role: string | null) => roles.find((r) => r.value === role)?.label ?? role ?? '—'

    const columns: Column<Row>[] = [
        {
            key: 'person',
            header: 'Person',
            render: (row) => (
                <div>
                    <span className="fw-semibold">{row.name ?? 'Unknown'}</span>
                    {row.is_self ? <span className="ms-2"><StatusBadge label="you" tone="info" /></span> : null}
                    <div className="small text-body-secondary">{row.email ?? '—'}</div>
                </div>
            ),
        },
        { key: 'role', header: 'Role', render: (row) => labelFor(row.role) },
        { key: 'branch', header: 'Branch', render: (row) => row.branch ?? 'All branches' },
        { key: 'employee_no', header: 'Employee no.', render: (row) => row.employee_no ?? '—' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.is_active ? 'active' : 'inactive'} tone={row.is_active ? 'success' : 'neutral'} />,
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            render: (row) =>
                row.is_self
                    ? <span className="small text-body-secondary">edit your own details in your profile</span>
                    : <Link href={`/admin/users/${row.id}/edit`} className="btn btn-sm btn-outline-secondary">Edit access</Link>,
        },
    ]

    return (
        <AppLayout>
            <Head title="People" />

            <PageHeader
                title="People"
                subtitle="Who can sign in, what role they hold, and which branch they see."
                actions={can.create ? <Link href="/admin/users/create" className="btn btn-sm btn-primary">Add person</Link> : null}
            />

            <div className="card">
                <div className="card-header bg-body">
                    <SearchBar action="/admin/users" initial={filters.q} placeholder="Name or email…" />
                </div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={members.data} rowKey={(row) => row.id} emptyTitle="Nobody here yet" />
                </div>
                <div className="card-footer bg-body"><Pagination meta={members} /></div>
            </div>

            <p className="form-text mt-3">
                You cannot change your own role or reach from this screen, and the last active owner cannot be demoted
                or deactivated — both are refused by the server, not just hidden here.
            </p>
        </AppLayout>
    )
}
