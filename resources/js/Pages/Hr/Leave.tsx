import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import StatusBadge from '@/Components/StatusBadge'
import DataTable, { type Column } from '@/Components/DataTable'

interface Balance {
    id: string
    name: string
    entitlement: string
    taken: string
    remaining: string
    is_paid: boolean
    requires_document: boolean
}

interface LeaveRow {
    id: string
    reference: string
    type: string | null
    employee: string | null
    status: string
    starts_on: string | null
    ends_on: string | null
    days: string
    reason: string
    decision_note: string | null
    started: boolean
}

interface Props {
    mine: LeaveRow[]
    balances: Balance[]
    awaitingMe: LeaveRow[]
    year: string
    can: { request: boolean; approve: boolean; configure: boolean }
}

const tone = (status: string) =>
    status === 'approved' ? 'success' : status === 'rejected' ? 'danger' : status === 'cancelled' ? 'neutral' : 'warning'

export default function Leave({ mine, balances, awaitingMe, year, can }: Props) {
    const [open, setOpen] = useState(false)
    const [decidingId, setDecidingId] = useState<string | null>(null)

    const ask = useForm({ leave_type_id: balances[0]?.id ?? '', starts_on: '', ends_on: '', reason: '' })
    const decide = useForm({ decision: 'approved', note: '' })
    const withdraw = useForm({})

    const mineColumns: Column<LeaveRow>[] = [
        {
            key: 'when',
            header: 'When',
            render: (row) => (
                <div>
                    <div>{row.starts_on} → {row.ends_on}</div>
                    <div className="small text-body-secondary font-monospace">{row.reference}</div>
                </div>
            ),
        },
        { key: 'type', header: 'Type', render: (row) => row.type ?? '—' },
        { key: 'days', header: 'Days', align: 'end', render: (row) => Number(row.days).toFixed(2) },
        { key: 'reason', header: 'Reason', render: (row) => row.reason },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <>
                    <StatusBadge label={row.status} tone={tone(row.status)} />
                    {row.decision_note ? <div className="small text-body-secondary">{row.decision_note}</div> : null}
                </>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            render: (row) =>
                can.request && ['pending', 'approved'].includes(row.status) && !row.started ? (
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary"
                        onClick={() => withdraw.post(`/leave/${row.id}/cancel`, { preserveScroll: true })}
                    >
                        Withdraw
                    </button>
                ) : null,
        },
    ]

    return (
        <AppLayout>
            <Head title="Leave" />

            <PageHeader
                title="Leave"
                subtitle={`Your entitlement, what you have taken, and what is still to be decided — ${year}.`}
                actions={
                    <>
                        {can.configure ? <Link href="/leave-types" className="btn btn-sm btn-outline-secondary">Leave types</Link> : null}
                        {can.request && balances.length > 0 ? (
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>Ask for leave</button>
                        ) : null}
                    </>
                }
            />

            {balances.length === 0 ? (
                <div className="alert alert-warning">
                    No leave types exist yet, so nobody can ask for leave.
                    {can.configure ? <> <Link href="/leave-types">Set them up</Link>.</> : ' An administrator has to set them up.'}
                </div>
            ) : (
                <div className="row g-3 mb-4">
                    {balances.map((balance) => (
                        <div key={balance.id} className="col-6 col-lg-3">
                            <StatCard
                                label={balance.name}
                                value={Number(balance.entitlement) === 0 ? '—' : Number(balance.remaining).toFixed(1)}
                                tone={Number(balance.remaining) < 0 ? 'danger' : Number(balance.remaining) <= 2 && Number(balance.entitlement) > 0 ? 'warning' : 'default'}
                                hint={
                                    Number(balance.entitlement) === 0
                                        ? 'No annual limit'
                                        : `${Number(balance.taken).toFixed(1)} of ${Number(balance.entitlement).toFixed(1)} taken`
                                }
                            />
                        </div>
                    ))}
                </div>
            )}

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Ask for leave</h2></div>
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                ask.post('/leave', { onSuccess: () => { ask.reset(); setOpen(false) } })
                            }}
                        >
                            <div className="col-12 col-md-3">
                                <label className="form-label" htmlFor="leave_type_id">Type</label>
                                <select id="leave_type_id" className="form-select" value={ask.data.leave_type_id} onChange={(e) => ask.setData('leave_type_id', e.target.value)}>
                                    {balances.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                </select>
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="starts_on">From</label>
                                <input id="starts_on" type="date" className={`form-control ${ask.errors.starts_on ? 'is-invalid' : ''}`} value={ask.data.starts_on} onChange={(e) => ask.setData('starts_on', e.target.value)} />
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="ends_on">To</label>
                                <input id="ends_on" type="date" className={`form-control ${ask.errors.ends_on ? 'is-invalid' : ''}`} value={ask.data.ends_on} onChange={(e) => ask.setData('ends_on', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-3">
                                <label className="form-label" htmlFor="reason">Reason</label>
                                <input id="reason" className={`form-control ${ask.errors.reason ? 'is-invalid' : ''}`} value={ask.data.reason} onChange={(e) => ask.setData('reason', e.target.value)} />
                                {ask.errors.reason ? <div className="invalid-feedback d-block">{ask.errors.reason}</div> : null}
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={ask.processing}>Send</button>
                            </div>
                            <div className="col-12">
                                <p className="form-text mb-0">
                                    Weekends are not counted. The days come off your balance as soon as you ask, not when
                                    it is approved — so two overlapping requests cannot both fit.
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            {awaitingMe.length > 0 ? (
                <div className="card mb-3">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Waiting on you</h2></div>
                    <div className="card-body p-0">
                        <ul className="list-group list-group-flush">
                            {awaitingMe.map((row) => (
                                <li key={row.id} className="list-group-item">
                                    <div className="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <span>
                                            <span className="fw-semibold">{row.employee}</span>
                                            <span className="small text-body-secondary d-block">
                                                {row.type} · {row.starts_on} → {row.ends_on} · {Number(row.days).toFixed(2)} day(s)
                                            </span>
                                            <span className="small">{row.reason}</span>
                                        </span>
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-outline-primary"
                                            onClick={() => setDecidingId((current) => (current === row.id ? null : row.id))}
                                        >
                                            Decide
                                        </button>
                                    </div>

                                    {decidingId === row.id ? (
                                        <form
                                            className="row g-2 align-items-end mt-2"
                                            onSubmit={(event) => {
                                                event.preventDefault()
                                                decide.post(`/leave/${row.id}/decide`, {
                                                    preserveScroll: true,
                                                    onSuccess: () => { decide.reset(); setDecidingId(null) },
                                                })
                                            }}
                                        >
                                            <div className="col-6 col-md-3">
                                                <label className="form-label small" htmlFor={`decision-${row.id}`}>Decision</label>
                                                <select id={`decision-${row.id}`} className="form-select form-select-sm" value={decide.data.decision} onChange={(e) => decide.setData('decision', e.target.value)}>
                                                    <option value="approved">Approve</option>
                                                    <option value="rejected">Reject</option>
                                                </select>
                                            </div>
                                            <div className="col-12 col-md-6">
                                                <label className="form-label small" htmlFor={`note-${row.id}`}>Note</label>
                                                <input id={`note-${row.id}`} className="form-control form-control-sm" value={decide.data.note} onChange={(e) => decide.setData('note', e.target.value)} />
                                            </div>
                                            <div className="col-12 col-md-3 d-grid">
                                                <button type="submit" className="btn btn-sm btn-primary" disabled={decide.processing}>Record</button>
                                            </div>
                                            <div className="col-12">
                                                <p className="form-text mb-0">A rejection needs a note — the person has to know what to do next.</p>
                                            </div>
                                        </form>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-header bg-body"><h2 className="h6 mb-0">Your leave</h2></div>
                <div className="card-body p-0">
                    <DataTable
                        columns={mineColumns}
                        rows={mine}
                        rowKey={(row) => row.id}
                        emptyTitle="You have not asked for any leave"
                    />
                </div>
            </div>
        </AppLayout>
    )
}
