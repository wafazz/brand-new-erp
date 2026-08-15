import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import DataTable, { type Column } from '@/Components/DataTable'
import EmptyState from '@/Components/EmptyState'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

interface Item {
    id: string
    sku: string
    product_name: string
    variant_name: string | null
    quantity: string
    unit_price: string
    unit_cost: string
    unit_cost_source: string | null
    discount_amount: string
    tax_amount: string
    line_total: string
    margin: string
}

interface TimelineEntry {
    id: string
    event: string
    summary: string
    actor: string
    at: string | null
}

interface CommissionRow {
    id: string
    recipient: string
    role: string | null
    status: string
    amount: string
    is_provisional: boolean
}

interface Transition {
    value: string
    label: string
}

interface Props {
    order: {
        id: string
        order_number: string
        customer_id: string | null
        customer_code: string | null
        customer_name: string
        customer_phone: string | null
        customer_email: string | null
        branch: string | null
        owner: string | null
        placed_at: string | null
        currency: string
        is_cod: boolean
        subtotal: string
        discount_amount: string
        tax_amount: string
        shipping_amount: string
        total: string
        paid_amount: string
        outstanding: string
        returned_amount: string
        refund_due: string
        notes: string | null
        payment_label: string
        payment_tone: Tone
        fulfilment_label: string
        fulfilment_tone: Tone
        exception: string
        exception_label: string
        exception_tone: Tone
    }
    items: Item[]
    attribution: {
        channel: string | null
        campaign: string | null
        marketer: string | null
        salesperson: string | null
        sales_team: string | null
        lead: string | null
        source: string | null
        medium: string | null
        captured_at: string | null
    } | null
    timeline: TimelineEntry[]
    commissions: CommissionRow[] | null
    invoice: { id: string; invoice_number: string; status: string; total: string; paid_amount: string; due_at: string | null } | null
    locks: Record<string, string | null>
    transitions: { payment: Transition[]; fulfilment: Transition[]; exception: Transition[] }
    permissions: {
        update: boolean
        approve: boolean
        cancel: boolean
        record_payment: boolean
        refund: boolean
        issue_invoice: boolean
    }
}

export default function OrderShow({ order, items, attribution, timeline, commissions, invoice, locks, transitions, permissions }: Props) {
    const [payingOpen, setPayingOpen] = useState(false)
    const [refundOpen, setRefundOpen] = useState(false)

    const transitionForm = useForm({ axis: 'fulfilment', status: '', reason: '' })
    const paymentForm = useForm({ amount: order.outstanding, method: 'cash', reference: '' })
    const refundForm = useForm({ amount: order.refund_due, method: 'bank_transfer', reference: '' })

    const move = (axis: 'payment' | 'fulfilment' | 'exception', status: string) => {
        transitionForm.transform(() => ({ axis, status, reason: '' }))
        transitionForm.post(`/orders/${order.id}/transition`, { preserveScroll: true })
    }

    const allowedToMove = (axis: 'payment' | 'fulfilment' | 'exception', status: string): boolean => {
        if (axis === 'fulfilment' && status === 'approved') {
            return permissions.approve
        }

        if (axis === 'exception' && status === 'cancelled') {
            return permissions.cancel
        }

        return permissions.update
    }

    const itemColumns: Column<Item>[] = [
        {
            key: 'product',
            header: 'Item',
            render: (row) => (
                <div>
                    <div>{row.product_name}{row.variant_name ? ` — ${row.variant_name}` : ''}</div>
                    <div className="small text-body-secondary font-monospace">{row.sku}</div>
                </div>
            ),
        },
        { key: 'qty', header: 'Qty', align: 'end', render: (row) => <span className="font-monospace">{Number(row.quantity).toFixed(2)}</span> },
        { key: 'price', header: 'Unit price', align: 'end', render: (row) => <MoneyText amount={row.unit_price} currency={order.currency} /> },
        {
            key: 'cost',
            header: 'Unit cost',
            align: 'end',
            render: (row) => (
                <span title={row.unit_cost_source ?? 'unknown source'}>
                    <MoneyText amount={row.unit_cost} currency={order.currency} muted />
                </span>
            ),
        },
        { key: 'tax', header: 'Tax', align: 'end', render: (row) => <MoneyText amount={row.tax_amount} currency={order.currency} muted /> },
        { key: 'total', header: 'Line total', align: 'end', render: (row) => <MoneyText amount={row.line_total} currency={order.currency} /> },
        { key: 'margin', header: 'Margin', align: 'end', render: (row) => <MoneyText amount={row.margin} currency={order.currency} muted /> },
    ]

    const commissionColumns: Column<CommissionRow>[] = [
        { key: 'recipient', header: 'Recipient', render: (row) => <span>{row.recipient}{row.role ? <span className="text-body-secondary small ms-2">{row.role}</span> : null}</span> },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status} tone={row.status === 'paid' ? 'success' : row.status === 'reversed' ? 'danger' : 'neutral'} /> },
        { key: 'provisional', header: 'Firm', render: (row) => (row.is_provisional ? <span className="text-body-secondary">provisional</span> : 'final') },
        { key: 'amount', header: 'Amount', align: 'end', render: (row) => <MoneyText amount={row.amount} currency={order.currency} /> },
    ]

    return (
        <AppLayout>
            <Head title={order.order_number} />

            <PageHeader
                title={order.order_number}
                subtitle={`${order.customer_name}${order.branch ? ` · ${order.branch}` : ''}${order.placed_at ? ` · ${order.placed_at}` : ''}`}
                actions={
                    <>
                        <Link href="/orders" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {invoice ? (
                            <Link href={`/invoices/${invoice.id}`} className="btn btn-sm btn-outline-primary">{invoice.invoice_number}</Link>
                        ) : null}
                        {permissions.record_payment && Number(order.outstanding) > 0 ? (
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => setPayingOpen((open) => !open)}>
                                Record payment
                            </button>
                        ) : null}
                        {permissions.refund && Number(order.refund_due) > 0 ? (
                            <button type="button" className="btn btn-sm btn-warning" onClick={() => setRefundOpen((open) => !open)}>
                                Refund {order.currency} {Number(order.refund_due).toFixed(2)}
                            </button>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={`Payment: ${order.payment_label}`} tone={order.payment_tone} />
                <StatusBadge label={`Fulfilment: ${order.fulfilment_label}`} tone={order.fulfilment_tone} />
                {order.exception !== 'none' ? <StatusBadge label={order.exception_label} tone={order.exception_tone} /> : null}
                {order.is_cod ? <StatusBadge label="Cash on delivery" tone="info" /> : null}
            </div>

            {payingOpen ? (
                <div className="card mb-3 border-primary">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Record a payment</h2></div>
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                paymentForm.post(`/orders/${order.id}/payments`, {
                                    preserveScroll: true,
                                    onSuccess: () => setPayingOpen(false),
                                })
                            }}
                        >
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="amount">Amount</label>
                                <input
                                    id="amount"
                                    className={`form-control text-end font-monospace ${paymentForm.errors.amount ? 'is-invalid' : ''}`}
                                    inputMode="decimal"
                                    value={paymentForm.data.amount}
                                    onChange={(e) => paymentForm.setData('amount', e.target.value)}
                                />
                                {paymentForm.errors.amount ? <div className="invalid-feedback d-block">{paymentForm.errors.amount}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="method">Method</label>
                                <select
                                    id="method"
                                    className="form-select"
                                    value={paymentForm.data.method}
                                    onChange={(e) => paymentForm.setData('method', e.target.value)}
                                >
                                    {['cash', 'card', 'bank_transfer', 'ewallet', 'cod', 'cheque'].map((method) => (
                                        <option key={method} value={method}>{method.replace('_', ' ')}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="reference">Reference</label>
                                <input
                                    id="reference"
                                    className="form-control"
                                    value={paymentForm.data.reference}
                                    onChange={(e) => paymentForm.setData('reference', e.target.value)}
                                />
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={paymentForm.processing}>
                                    {paymentForm.processing ? 'Saving…' : 'Record'}
                                </button>
                            </div>
                        </form>
                        <p className="form-text mb-0">
                            The payment status follows the money — recording enough to settle the order moves it to paid by itself.
                        </p>
                    </div>
                </div>
            ) : null}

            {Number(order.refund_due) > 0 ? (
                <div className="alert alert-warning">
                    <strong>This order owes the customer <MoneyText amount={order.refund_due} currency={order.currency} />.</strong>{' '}
                    Goods worth <MoneyText amount={order.returned_amount} currency={order.currency} muted /> came back but the money has not
                    gone out yet.
                </div>
            ) : null}

            {refundOpen ? (
                <div className="card mb-3 border-warning">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Refund the customer</h2></div>
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                refundForm.post(`/orders/${order.id}/refunds`, {
                                    preserveScroll: true,
                                    onSuccess: () => setRefundOpen(false),
                                })
                            }}
                        >
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="refund_amount">Amount</label>
                                <input
                                    id="refund_amount"
                                    className={`form-control text-end font-monospace ${refundForm.errors.amount ? 'is-invalid' : ''}`}
                                    inputMode="decimal"
                                    value={refundForm.data.amount}
                                    onChange={(e) => refundForm.setData('amount', e.target.value)}
                                />
                                {refundForm.errors.amount ? <div className="invalid-feedback d-block">{refundForm.errors.amount}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="refund_method">Method</label>
                                <select
                                    id="refund_method"
                                    className="form-select"
                                    value={refundForm.data.method}
                                    onChange={(e) => refundForm.setData('method', e.target.value)}
                                >
                                    {['bank_transfer', 'cash', 'card', 'ewallet', 'cheque'].map((method) => (
                                        <option key={method} value={method}>{method.replace('_', ' ')}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="refund_reference">Reference</label>
                                <input
                                    id="refund_reference"
                                    className="form-control"
                                    value={refundForm.data.reference}
                                    onChange={(e) => refundForm.setData('reference', e.target.value)}
                                />
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-warning" disabled={refundForm.processing}>
                                    {refundForm.processing ? 'Saving…' : 'Refund'}
                                </button>
                            </div>
                            <div className="col-12">
                                <p className="form-text mb-0">
                                    You can only refund what the returned goods are worth. Record the goods coming back first —
                                    the server refuses anything beyond that.
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="row g-3">
                <div className="col-12 col-xl-8">
                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Lines</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={itemColumns} rows={items} rowKey={(row) => row.id} emptyTitle="No lines" />
                        </div>
                        <div className="card-footer bg-body">
                            <dl className="row mb-0 small justify-content-end">
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Subtotal</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={order.subtotal} currency={order.currency} /></dd>
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Discount</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={order.discount_amount} currency={order.currency} muted /></dd>
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Tax</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={order.tax_amount} currency={order.currency} muted /></dd>
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Shipping</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={order.shipping_amount} currency={order.currency} muted /></dd>
                                <dt className="col-8 col-sm-9 text-end">Total</dt>
                                <dd className="col-4 col-sm-3 text-end fw-semibold mb-1"><MoneyText amount={order.total} currency={order.currency} /></dd>
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Paid</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={order.paid_amount} currency={order.currency} muted /></dd>
                                {Number(order.returned_amount) > 0 ? (
                                    <>
                                        <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Returned</dt>
                                        <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={order.returned_amount} currency={order.currency} muted /></dd>
                                    </>
                                ) : null}
                                <dt className="col-8 col-sm-9 text-end">{Number(order.refund_due) > 0 ? 'Owed to customer' : 'Outstanding'}</dt>
                                <dd className="col-4 col-sm-3 text-end fw-semibold mb-0">
                                    <MoneyText
                                        amount={Number(order.refund_due) > 0 ? order.refund_due : order.outstanding}
                                        currency={order.currency}
                                    />
                                </dd>
                            </dl>
                        </div>
                    </div>

                    {commissions === null ? null : (
                        <div className="card mb-3">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Commission accrued</h2></div>
                            <div className="card-body p-0">
                                <DataTable
                                    columns={commissionColumns}
                                    rows={commissions}
                                    rowKey={(row) => row.id}
                                    emptyTitle="No commission yet"
                                    emptyDescription="Commission accrues when the order reaches a state the plan pays on."
                                />
                            </div>
                        </div>
                    )}

                    <div className="card">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">History</h2></div>
                        <div className="card-body">
                            {timeline.length === 0 ? (
                                <EmptyState title="No history" />
                            ) : (
                                <ol className="list-unstyled mb-0">
                                    {timeline.map((entry) => (
                                        <li key={entry.id} className="d-flex gap-3 pb-3">
                                            <div className="text-body-secondary small text-nowrap" style={{ minWidth: '11rem' }}>{entry.at}</div>
                                            <div>
                                                <div className="small fw-semibold font-monospace">{entry.event}</div>
                                                <div className="small">{entry.summary}</div>
                                                <div className="small text-body-secondary">{entry.actor}</div>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                        <div className="card-footer bg-body small text-body-secondary">
                            Order events are append-only. Nothing on this list can be edited or removed, in the app or in the database.
                        </div>
                    </div>
                </div>

                <div className="col-12 col-xl-4">
                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Move this order</h2></div>
                        <div className="card-body">
                            {(['fulfilment', 'payment', 'exception'] as const).map((axis) => (
                                <div key={axis} className="mb-3">
                                    <div className="text-uppercase small fw-semibold text-body-secondary mb-2">{axis}</div>
                                    {transitions[axis].length === 0 ? (
                                        <p className="small text-body-secondary mb-0">Nowhere left to go on this track.</p>
                                    ) : (
                                        <div className="d-flex flex-wrap gap-2">
                                            {transitions[axis].map((option) => {
                                                const permitted = allowedToMove(axis, option.value)

                                                return (
                                                    <button
                                                        key={option.value}
                                                        type="button"
                                                        className={`btn btn-sm ${option.value === 'cancelled' ? 'btn-outline-danger' : 'btn-outline-primary'}`}
                                                        disabled={!permitted || transitionForm.processing}
                                                        title={permitted ? undefined : 'Your role cannot make this move.'}
                                                        onClick={() => move(axis, option.value)}
                                                    >
                                                        {option.label}
                                                    </button>
                                                )
                                            })}
                                        </div>
                                    )}
                                </div>
                            ))}
                            <p className="form-text mb-0">
                                Only moves the state machine allows appear here, and the server checks the same rule again
                                before it applies one.
                            </p>
                        </div>
                    </div>

                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Attribution</h2></div>
                        <div className="card-body">
                            {attribution === null ? (
                                <p className="small text-body-secondary mb-0">This order carries no attribution record.</p>
                            ) : (
                                <>
                                    <dl className="row mb-0 small">
                                        <dt className="col-5">Salesperson</dt>
                                        <dd className="col-7">{attribution.salesperson ?? '—'}</dd>
                                        <dt className="col-5">Sales team</dt>
                                        <dd className="col-7">{attribution.sales_team ?? '—'}</dd>
                                        <dt className="col-5">Channel</dt>
                                        <dd className="col-7">{attribution.channel ?? '—'}</dd>
                                        <dt className="col-5">Campaign</dt>
                                        <dd className="col-7">{attribution.campaign ?? '—'}</dd>
                                        <dt className="col-5">Marketer</dt>
                                        <dd className="col-7">{attribution.marketer ?? '—'}</dd>
                                        <dt className="col-5">Lead</dt>
                                        <dd className="col-7">{attribution.lead ?? '—'}</dd>
                                        <dt className="col-5">Source</dt>
                                        <dd className="col-7">{attribution.source ?? '—'}</dd>
                                        <dt className="col-5">Captured</dt>
                                        <dd className="col-7">{attribution.captured_at ?? '—'}</dd>
                                    </dl>
                                    <p className="form-text mb-0">
                                        Frozen when the order was created. It never moves afterwards, because commission is paid on it.
                                    </p>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Customer</h2></div>
                        <div className="card-body">
                            <dl className="row mb-0 small">
                                <dt className="col-4">Name</dt>
                                <dd className="col-8">
                                    {order.customer_id ? (
                                        <Link href={`/customers/${order.customer_id}`}>{order.customer_name}</Link>
                                    ) : (
                                        order.customer_name
                                    )}
                                </dd>
                                <dt className="col-4">Phone</dt>
                                <dd className="col-8">{order.customer_phone ?? '—'}</dd>
                                <dt className="col-4">Email</dt>
                                <dd className="col-8">{order.customer_email ?? '—'}</dd>
                                <dt className="col-4">Owner</dt>
                                <dd className="col-8">{order.owner ?? 'Unassigned'}</dd>
                            </dl>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">What is locked</h2></div>
                        <div className="card-body">
                            <dl className="row mb-0 small">
                                {Object.entries(locks).map(([group, reason]) => (
                                    <div key={group} className="d-flex gap-2 mb-2">
                                        <dt className="text-capitalize" style={{ minWidth: '5rem' }}>{group}</dt>
                                        <dd className="mb-0">
                                            {reason === null ? <span className="text-success">editable</span> : <span className="text-body-secondary">{reason}</span>}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
