import { Head } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import ExportButton from '@/Components/ExportButton'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'

interface Entry {
    id: string
    action: string
    module: string
    actor: string | null
    branch: string | null
    reason: string | null
    created_at: string | null
}

interface Props {
    entries: Entry[]
}

const columns: Column<Entry>[] = [
    { key: 'created_at', header: 'When', render: (row) => (row.created_at ? new Date(row.created_at).toLocaleString('en-MY') : '—') },
    { key: 'actor', header: 'Who', render: (row) => row.actor ?? 'System' },
    { key: 'action', header: 'Action', render: (row) => <StatusBadge label={row.action} tone="info" /> },
    { key: 'module', header: 'Module', render: (row) => row.module },
    { key: 'branch', header: 'Branch', render: (row) => row.branch ?? '—' },
    { key: 'reason', header: 'Reason', render: (row) => row.reason ?? '—' },
]

export default function AuditLogIndex({ entries }: Props) {
    return (
        <AppLayout>
            <Head title="Audit log" />
            <PageHeader
                title="Audit log"
                subtitle="Only entries your role and data scope permit."
                actions={<ExportButton exportKey="audit" ability="audit.export" />}
            />
            <div className="card">
                <div className="card-body">
                    <DataTable
                        columns={columns}
                        rows={entries}
                        rowKey={(row) => row.id}
                        emptyTitle="No audit entries visible"
                        emptyDescription="Your data scope may limit this to your own activity."
                    />
                </div>
            </div>
        </AppLayout>
    )
}
