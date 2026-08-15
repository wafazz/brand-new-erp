import { Head, Link } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import EmptyState from '@/Components/EmptyState'
import { useAuth } from '@/Hooks/useAuth'

interface BoardLead {
    id: string
    reference: string | null
    name: string
    assignee: string | null
    estimated_value: string
}

interface Column {
    id: string | null
    name: string
    probability: string
    is_won: boolean
    is_lost: boolean
    value: string
    weighted: string
    leads: BoardLead[]
}

interface FollowUp {
    id: string
    lead_id: string | null
    subject: string
    reference: string | null
    type: string
    summary: string
    follow_up_at: string | null
    overdue: boolean
}

interface Props {
    columns: Column[]
    followUps: FollowUp[]
    can: { update: boolean; configure: boolean }
}

export default function PipelineBoard({ columns, followUps, can }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const open = columns.filter((c) => !c.is_won && !c.is_lost)
    const totalWeighted = open.reduce((sum, c) => sum + Number(c.weighted), 0)
    const totalValue = open.reduce((sum, c) => sum + Number(c.value), 0)

    return (
        <AppLayout>
            <Head title="Pipeline" />

            <PageHeader
                title="Pipeline"
                subtitle="What is still open, what it is worth, and what that is worth once you weight it by the odds."
                actions={can.configure ? <Link href="/pipeline/stages" className="btn btn-sm btn-outline-secondary">Stages</Link> : null}
            />

            <div className="alert alert-secondary d-flex flex-wrap gap-4">
                <span>
                    Open pipeline <strong><MoneyText amount={String(totalValue)} currency={currency} /></strong>
                </span>
                <span>
                    Weighted <strong><MoneyText amount={String(totalWeighted)} currency={currency} /></strong>
                </span>
                <span className="text-body-secondary small ms-auto">
                    Weighted value is each stage's total multiplied by its probability. It is a forecast, not a promise.
                </span>
            </div>

            {followUps.length > 0 ? (
                <div className="card mb-3">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Follow up now</h2></div>
                    <div className="card-body p-0">
                        <ul className="list-group list-group-flush">
                            {followUps.map((row) => (
                                <li key={row.id} className="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        {row.lead_id ? (
                                            <Link href={`/leads/${row.lead_id}`} className="fw-semibold text-decoration-none">{row.subject}</Link>
                                        ) : (
                                            <span className="fw-semibold">{row.subject}</span>
                                        )}
                                        <span className="small text-body-secondary d-block">{row.type} · {row.summary}</span>
                                    </span>
                                    <span className="text-end">
                                        <span className={`small ${row.overdue ? 'text-danger fw-semibold' : 'text-body-secondary'}`}>
                                            {row.follow_up_at}
                                        </span>
                                        {row.overdue ? <span className="ms-2"><StatusBadge label="overdue" tone="danger" /></span> : null}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            ) : null}

            {columns.length === 0 ? (
                <div className="card">
                    <div className="card-body">
                        <EmptyState
                            title="No pipeline stages yet"
                            description="Leads have nowhere to sit until stages exist. Create them under Stages."
                            action={can.configure ? <Link href="/pipeline/stages" className="btn btn-sm btn-primary">Set up stages</Link> : undefined}
                        />
                    </div>
                </div>
            ) : (
                <div className="d-flex gap-3 overflow-auto pb-2">
                    {columns.map((column) => (
                        <div key={column.id ?? 'none'} className="card flex-shrink-0" style={{ width: '17rem' }}>
                            <div className="card-header bg-body">
                                <div className="d-flex justify-content-between align-items-start">
                                    <h2 className="h6 mb-0">{column.name}</h2>
                                    {column.is_won ? <StatusBadge label="won" tone="success" /> : null}
                                    {column.is_lost ? <StatusBadge label="lost" tone="danger" /> : null}
                                </div>
                                <div className="small text-body-secondary">
                                    {column.leads.length} · <MoneyText amount={column.value} currency={currency} muted />
                                    {Number(column.probability) > 0 ? ` · ${column.probability}%` : ''}
                                </div>
                            </div>
                            <div className="card-body p-2">
                                {column.leads.length === 0 ? (
                                    <p className="small text-body-secondary mb-0">Nothing here.</p>
                                ) : (
                                    column.leads.map((lead) => (
                                        <Link
                                            key={lead.id}
                                            href={`/leads/${lead.id}`}
                                            className="d-block border rounded p-2 mb-2 text-decoration-none"
                                        >
                                            <div className="fw-semibold small">{lead.name}</div>
                                            <div className="small text-body-secondary font-monospace">{lead.reference ?? '—'}</div>
                                            <div className="d-flex justify-content-between align-items-center mt-1">
                                                <span className="small text-body-secondary">{lead.assignee ?? 'Unassigned'}</span>
                                                <MoneyText amount={lead.estimated_value} currency={currency} />
                                            </div>
                                        </Link>
                                    ))
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </AppLayout>
    )
}
