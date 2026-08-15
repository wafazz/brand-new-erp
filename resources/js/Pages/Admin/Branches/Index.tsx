import { Head } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import { useAuth } from '@/Hooks/useAuth'

interface BranchRow {
    id: string
    code: string
    name: string
    city: string | null
    is_default: boolean
    is_active: boolean
}

interface Props {
    branches: BranchRow[]
}

export default function BranchIndex({ branches }: Props) {
    const { can } = useAuth()

    const columns: Column<BranchRow>[] = [
        { key: 'code', header: 'Code', render: (row) => <span className="font-monospace">{row.code}</span> },
        { key: 'name', header: 'Name', render: (row) => row.name },
        { key: 'city', header: 'City', render: (row) => row.city ?? '—' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <StatusBadge
                    label={row.is_active ? 'Active' : 'Inactive'}
                    tone={row.is_active ? 'success' : 'neutral'}
                />
            ),
        },
    ]

    return (
        <AppLayout>
            <Head title="Branches" />
            <PageHeader
                title="Branches"
                subtitle="Branches scope what your team can see."
                actions={can('branches.create') ? <button type="button" className="btn btn-primary btn-sm">New branch</button> : null}
            />
            <div className="card">
                <div className="card-body">
                    <DataTable
                        columns={columns}
                        rows={branches}
                        rowKey={(row) => row.id}
                        emptyTitle="No branches yet"
                    />
                </div>
            </div>
        </AppLayout>
    )
}
