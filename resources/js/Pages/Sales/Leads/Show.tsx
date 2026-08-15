import { Head, Link } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import EmptyState from '@/Components/EmptyState'
import { useAuth } from '@/Hooks/useAuth'

interface Activity {
    id: string
    type: string
    summary: string | null
    actor: string
    at: string | null
}

interface Props {
    lead: {
        id: string
        reference: string | null
        name: string
        phone: string | null
        email: string | null
        status: string
        stage: string | null
        assignee: string | null
        branch: string | null
        estimated_value: string
        captured_at: string | null
        converted_at: string | null
        converted_order_id: string | null
        note: string | null
    }
    activities: Activity[]
    permissions: { update: boolean; convert: boolean }
}

export default function LeadShow({ lead, activities, permissions }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    return (
        <AppLayout>
            <Head title={lead.name} />

            <PageHeader
                title={lead.name}
                subtitle={`${lead.reference ?? '—'}${lead.stage ? ` · ${lead.stage}` : ''}`}
                actions={
                    <>
                        <Link href="/leads" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {permissions.update ? <Link href={`/leads/${lead.id}/edit`} className="btn btn-sm btn-primary">Edit</Link> : null}
                        {lead.converted_order_id ? (
                            <Link href={`/orders/${lead.converted_order_id}`} className="btn btn-sm btn-outline-primary">Converted order</Link>
                        ) : permissions.convert ? (
                            <Link href="/orders/create" className="btn btn-sm btn-outline-primary">Convert to order</Link>
                        ) : null}
                    </>
                }
            />

            <div className="row g-3">
                <div className="col-12 col-lg-5">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Details</h2></div>
                        <div className="card-body">
                            <dl className="row mb-0 small">
                                <dt className="col-5">Status</dt>
                                <dd className="col-7">
                                    <StatusBadge
                                        label={lead.status}
                                        tone={lead.status === 'won' ? 'success' : lead.status === 'lost' ? 'danger' : 'info'}
                                    />
                                </dd>
                                <dt className="col-5">Phone</dt>
                                <dd className="col-7">{lead.phone ?? '—'}</dd>
                                <dt className="col-5">Email</dt>
                                <dd className="col-7">{lead.email ?? '—'}</dd>
                                <dt className="col-5">Owner</dt>
                                <dd className="col-7">{lead.assignee ?? 'Unassigned'}</dd>
                                <dt className="col-5">Branch</dt>
                                <dd className="col-7">{lead.branch ?? '—'}</dd>
                                <dt className="col-5">Estimated value</dt>
                                <dd className="col-7"><MoneyText amount={lead.estimated_value} currency={currency} /></dd>
                                <dt className="col-5">Captured</dt>
                                <dd className="col-7">{lead.captured_at ?? '—'}</dd>
                                <dt className="col-5">Converted</dt>
                                <dd className="col-7">{lead.converted_at ?? 'Not yet'}</dd>
                            </dl>
                            {lead.note ? <p className="small text-body-secondary mt-3 mb-0">{lead.note}</p> : null}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-7">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Activity</h2></div>
                        <div className="card-body">
                            {activities.length === 0 ? (
                                <EmptyState title="Nothing logged yet" description="Calls, messages and meetings appear here once recorded." />
                            ) : (
                                <ol className="list-unstyled mb-0">
                                    {activities.map((activity) => (
                                        <li key={activity.id} className="d-flex gap-3 pb-3">
                                            <div className="text-body-secondary small text-nowrap" style={{ minWidth: '11rem' }}>{activity.at}</div>
                                            <div>
                                                <div className="small fw-semibold">{activity.type}</div>
                                                {activity.summary ? <div className="small">{activity.summary}</div> : null}
                                                <div className="small text-body-secondary">{activity.actor}</div>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
