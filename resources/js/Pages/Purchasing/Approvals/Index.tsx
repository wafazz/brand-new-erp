import { Head, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import EmptyState from '@/Components/EmptyState'
import Pagination from '@/Components/Pagination'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    subject: string
    flow: string
    amount: string
    formatted_amount: string
    requester: string
    current_sequence: number
    raised_at: string | null
    blocked_reason: string | null
}

interface Props {
    requests: Paginated<Row>
}

export default function ApprovalInbox({ requests }: Props) {
    const [openId, setOpenId] = useState<string | null>(null)
    const decide = useForm({ decision: 'approve', comment: '' })

    return (
        <AppLayout>
            <Head title="Approvals" />

            <PageHeader
                title="Approvals"
                subtitle="Everything waiting on a decision. What you cannot decide says why."
            />

            {requests.data.length === 0 ? (
                <div className="card"><div className="card-body"><EmptyState title="Nothing waiting" description="No approval request is pending." /></div></div>
            ) : (
                <div className="d-flex flex-column gap-3">
                    {requests.data.map((row) => (
                        <div key={row.id} className={`card ${row.blocked_reason === null ? '' : 'opacity-75'}`}>
                            <div className="card-body">
                                <div className="d-flex flex-wrap justify-content-between align-items-start gap-3">
                                    <div>
                                        <h2 className="h6 mb-1">{row.subject}</h2>
                                        <div className="small text-body-secondary">
                                            {row.flow} · level {row.current_sequence} · raised by {row.requester}
                                            {row.raised_at ? ` · ${row.raised_at}` : ''}
                                        </div>
                                    </div>
                                    <div className="text-end">
                                        <div className="font-monospace fw-semibold">{row.formatted_amount}</div>
                                        {row.blocked_reason === null ? (
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-primary mt-2"
                                                onClick={() => setOpenId((current) => (current === row.id ? null : row.id))}
                                            >
                                                Decide
                                            </button>
                                        ) : null}
                                    </div>
                                </div>

                                {row.blocked_reason !== null ? (
                                    <div className="alert alert-secondary py-2 mb-0 mt-3 small">{row.blocked_reason}</div>
                                ) : null}

                                {openId === row.id ? (
                                    <form
                                        className="row g-2 align-items-end mt-2"
                                        onSubmit={(event) => {
                                            event.preventDefault()
                                            decide.post(`/approvals/${row.id}/decide`, { preserveScroll: true, onSuccess: () => setOpenId(null) })
                                        }}
                                    >
                                        <div className="col-12 col-md-3">
                                            <label className="form-label" htmlFor={`decision-${row.id}`}>Decision</label>
                                            <select
                                                id={`decision-${row.id}`}
                                                className="form-select"
                                                value={decide.data.decision}
                                                onChange={(e) => decide.setData('decision', e.target.value)}
                                            >
                                                <option value="approve">Approve</option>
                                                <option value="reject">Reject</option>
                                                <option value="return">Return for revision</option>
                                            </select>
                                        </div>
                                        <div className="col-12 col-md-6">
                                            <label className="form-label" htmlFor={`comment-${row.id}`}>Comment</label>
                                            <input
                                                id={`comment-${row.id}`}
                                                className={`form-control ${decide.errors.comment ? 'is-invalid' : ''}`}
                                                value={decide.data.comment}
                                                onChange={(e) => decide.setData('comment', e.target.value)}
                                            />
                                            {decide.errors.comment ? <div className="invalid-feedback d-block">{decide.errors.comment}</div> : null}
                                        </div>
                                        <div className="col-12 col-md-3 d-grid">
                                            <button type="submit" className="btn btn-primary" disabled={decide.processing}>Record decision</button>
                                        </div>
                                    </form>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <div className="mt-3"><Pagination meta={requests} /></div>
        </AppLayout>
    )
}
