import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import ApprovalTrail, { type ApprovalPanel } from '@/Components/ApprovalTrail'
import { statusTone } from './Index'

interface Item {
    id: string
    sku: string | null
    product: string | null
    variant: string | null
    quantity: string
    note: string | null
}

interface Props {
    request: {
        id: string
        reference: string | null
        status: string
        requester: string | null
        branch: string | null
        needed_by: string | null
        note: string | null
        created_at: string | null
    }
    items: Item[]
    approval: ApprovalPanel | null
    permissions: { submit: boolean; approve: boolean; raise_order: boolean }
}

export default function PurchaseRequestShow({ request, items, approval, permissions }: Props) {
    const [decidingOpen, setDecidingOpen] = useState(false)

    const submit = useForm({})
    const decide = useForm({ decision: 'approved', comment: '' })

    const columns: Column<Item>[] = [
        {
            key: 'item',
            header: 'Item',
            render: (row) => (
                <div>
                    <div>{row.product ?? 'Unknown item'}{row.variant ? ` — ${row.variant}` : ''}</div>
                    <div className="small text-body-secondary font-monospace">{row.sku ?? '—'}</div>
                </div>
            ),
        },
        { key: 'quantity', header: 'Quantity', align: 'end', render: (row) => <span className="font-monospace">{Number(row.quantity).toFixed(2)}</span> },
        { key: 'note', header: 'Note', render: (row) => row.note ?? '—' },
    ]

    return (
        <AppLayout>
            <Head title={request.reference ?? 'Purchase request'} />

            <PageHeader
                title={request.reference ?? 'Purchase request'}
                subtitle={`${request.requester ?? 'Unknown'}${request.branch ? ` · ${request.branch}` : ''}${request.created_at ? ` · ${request.created_at}` : ''}`}
                actions={
                    <>
                        <Link href="/purchase-requests" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {permissions.submit && request.status === 'draft' ? (
                            <button
                                type="button"
                                className="btn btn-sm btn-primary"
                                disabled={submit.processing}
                                onClick={() => submit.post(`/purchase-requests/${request.id}/submit`, { preserveScroll: true })}
                            >
                                Submit for approval
                            </button>
                        ) : null}
                        {permissions.approve && request.status === 'pending' ? (
                            <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setDecidingOpen((open) => !open)}>
                                Decide
                            </button>
                        ) : null}
                        {permissions.raise_order && request.status === 'approved' ? (
                            <Link href={`/purchase-orders/create?from_request=${request.id}`} className="btn btn-sm btn-primary">
                                Raise purchase order
                            </Link>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={request.status} tone={statusTone(request.status)} />
                {request.needed_by ? <StatusBadge label={`Needed by ${request.needed_by}`} tone="info" /> : null}
            </div>

            {decidingOpen ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                decide.post(`/purchase-requests/${request.id}/decide`, { preserveScroll: true, onSuccess: () => setDecidingOpen(false) })
                            }}
                        >
                            <div className="col-12 col-md-3">
                                <label className="form-label" htmlFor="decision">Decision</label>
                                <select id="decision" className="form-select" value={decide.data.decision} onChange={(e) => decide.setData('decision', e.target.value)}>
                                    <option value="approved">Approve</option>
                                    <option value="rejected">Reject</option>
                                </select>
                            </div>
                            <div className="col-12 col-md-6">
                                <label className="form-label" htmlFor="comment">Comment</label>
                                <input id="comment" className="form-control" value={decide.data.comment} onChange={(e) => decide.setData('comment', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-3 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={decide.processing}>Record decision</button>
                            </div>
                        </form>
                        <p className="form-text mb-0">
                            Where an approval flow is configured, this goes through it — including the rule that nobody
                            approves their own request.
                        </p>
                    </div>
                </div>
            ) : null}

            <div className="row g-3">
                <div className="col-12 col-lg-8">
                    <div className="card">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Requested items</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} rows={items} rowKey={(row) => row.id} emptyTitle="No lines" />
                        </div>
                        {request.note ? <div className="card-footer bg-body small text-body-secondary">{request.note}</div> : null}
                    </div>
                </div>

                <div className="col-12 col-lg-4">
                    <ApprovalTrail
                        approval={approval}
                        emptyNote="No approval flow is configured for purchase requests, so a decision here is recorded directly against the request."
                    />
                </div>
            </div>
        </AppLayout>
    )
}
