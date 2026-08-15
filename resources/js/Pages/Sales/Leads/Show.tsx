import { Head, Link, useForm } from '@inertiajs/react'
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

interface Option {
    value: string
    label: string
}

interface FollowUp {
    id: string
    summary: string
    follow_up_at: string | null
    overdue: boolean
}

interface Props {
    stages: Option[]
    contactTypes: Option[]
    followUps: FollowUp[]
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

export default function LeadShow({ lead, activities, permissions, stages, contactTypes, followUps }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const contact = useForm({
        type: contactTypes[0]?.value ?? 'call',
        summary: '',
        note: '',
        follow_up_at: '',
    })

    const move = useForm({ pipeline_stage_id: '', note: '' })

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
                    {permissions.update && lead.converted_order_id === null ? (
                        <div className="card mb-3">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Log a contact</h2></div>
                            <div className="card-body">
                                <form
                                    className="row g-2 align-items-end"
                                    onSubmit={(event) => {
                                        event.preventDefault()
                                        contact.post(`/leads/${lead.id}/contacts`, {
                                            preserveScroll: true,
                                            onSuccess: () => contact.reset(),
                                        })
                                    }}
                                >
                                    <div className="col-6 col-md-3">
                                        <label className="form-label small" htmlFor="type">How</label>
                                        <select id="type" className="form-select form-select-sm" value={contact.data.type} onChange={(e) => contact.setData('type', e.target.value)}>
                                            {contactTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                                        </select>
                                    </div>
                                    <div className="col-12 col-md-5">
                                        <label className="form-label small" htmlFor="summary">What happened</label>
                                        <input
                                            id="summary"
                                            className={`form-control form-control-sm ${contact.errors.summary ? 'is-invalid' : ''}`}
                                            value={contact.data.summary}
                                            onChange={(e) => contact.setData('summary', e.target.value)}
                                        />
                                        {contact.errors.summary ? <div className="invalid-feedback d-block">{contact.errors.summary}</div> : null}
                                    </div>
                                    <div className="col-6 col-md-4">
                                        <label className="form-label small" htmlFor="follow_up_at">Follow up on</label>
                                        <input
                                            id="follow_up_at"
                                            type="date"
                                            className={`form-control form-control-sm ${contact.errors.follow_up_at ? 'is-invalid' : ''}`}
                                            value={contact.data.follow_up_at}
                                            onChange={(e) => contact.setData('follow_up_at', e.target.value)}
                                        />
                                    </div>
                                    <div className="col-12">
                                        <button type="submit" className="btn btn-sm btn-primary" disabled={contact.processing}>
                                            {contact.processing ? 'Saving…' : 'Log it'}
                                        </button>
                                        <span className="form-text ms-3">
                                            A follow-up date puts this lead on the pipeline board's list for that day.
                                        </span>
                                    </div>
                                </form>

                                {stages.length > 0 ? (
                                    <form
                                        className="row g-2 align-items-end mt-3 pt-3 border-top"
                                        onSubmit={(event) => {
                                            event.preventDefault()
                                            move.post(`/leads/${lead.id}/stage`, { preserveScroll: true, onSuccess: () => move.reset() })
                                        }}
                                    >
                                        <div className="col-12 col-md-5">
                                            <label className="form-label small" htmlFor="pipeline_stage_id">Move to stage</label>
                                            <select
                                                id="pipeline_stage_id"
                                                className="form-select form-select-sm"
                                                value={move.data.pipeline_stage_id}
                                                onChange={(e) => move.setData('pipeline_stage_id', e.target.value)}
                                            >
                                                <option value="">Choose a stage…</option>
                                                {stages.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                                            </select>
                                        </div>
                                        <div className="col-12 col-md-4">
                                            <label className="form-label small" htmlFor="move_note">Note</label>
                                            <input id="move_note" className="form-control form-control-sm" value={move.data.note} onChange={(e) => move.setData('note', e.target.value)} />
                                        </div>
                                        <div className="col-12 col-md-3 d-grid">
                                            <button type="submit" className="btn btn-sm btn-outline-primary" disabled={move.processing || move.data.pipeline_stage_id === ''}>
                                                Move
                                            </button>
                                        </div>
                                    </form>
                                ) : null}
                            </div>
                        </div>
                    ) : null}

                    {followUps.length > 0 ? (
                        <div className="card mb-3">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Follow-ups</h2></div>
                            <div className="card-body p-0">
                                <ul className="list-group list-group-flush">
                                    {followUps.map((row) => (
                                        <li key={row.id} className="list-group-item d-flex justify-content-between align-items-center">
                                            <span className="small">{row.summary}</span>
                                            <span className={`small ${row.overdue ? 'text-danger fw-semibold' : 'text-body-secondary'}`}>
                                                {row.follow_up_at}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    ) : null}

                    <div className="card">
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
