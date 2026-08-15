import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import DataTable, { type Column } from '@/Components/DataTable'
import { subTone } from './Index'

interface Charge {
    id: string
    order_number: string
    period: string | null
    total: string
    paid: string
    currency: string
}

interface Props {
    subscription: {
        id: string
        reference: string
        customer_id: string | null
        customer: string | null
        plan: string | null
        interval: string | null
        status: string
        quantity: string
        unit_price: string
        charge: string
        currency: string
        starts_on: string | null
        next_invoice_on: string | null
        ends_on: string | null
        cancel_reason: string | null
        collect_online: boolean
    }
    charges: Charge[]
    paymentLinks: PaymentLink[]
    can: { manage: boolean; collect_online: boolean }
}

interface PaymentLink {
    invoice_id: string
    invoice_number: string
    status: string
    outstanding: string
    pay_url: string | null
}

export default function SubscriptionShow({ subscription, charges, paymentLinks, can }: Props) {
    const [cancelling, setCancelling] = useState(false)

    const act = useForm({})
    const cancel = useForm({ reason: '' })
    const collect = useForm({ collect_online: !subscription.collect_online })

    const columns: Column<Charge>[] = [
        {
            key: 'order',
            header: 'Charge',
            render: (row) => (
                <Link href={`/orders/${row.id}`} className="font-monospace text-decoration-none">{row.order_number}</Link>
            ),
        },
        { key: 'period', header: 'Period', render: (row) => row.period ?? '—' },
        { key: 'total', header: 'Amount', align: 'end', render: (row) => <MoneyText amount={row.total} currency={row.currency} /> },
        {
            key: 'paid',
            header: 'Paid',
            align: 'end',
            render: (row) =>
                Number(row.paid) >= Number(row.total)
                    ? <span className="text-success">settled</span>
                    : <MoneyText amount={row.paid} currency={row.currency} muted />,
        },
    ]

    return (
        <AppLayout>
            <Head title={subscription.reference} />

            <PageHeader
                title={subscription.customer ?? 'Subscription'}
                subtitle={`${subscription.reference} · ${subscription.plan ?? ''} · ${subscription.interval ?? ''}`}
                actions={
                    <>
                        <Link href="/subscriptions" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {can.manage && subscription.status === 'active' ? (
                            <button type="button" className="btn btn-sm btn-outline-warning" onClick={() => act.post(`/subscriptions/${subscription.id}/pause`, { preserveScroll: true })}>Pause</button>
                        ) : null}
                        {can.manage && subscription.status === 'paused' ? (
                            <button type="button" className="btn btn-sm btn-outline-success" onClick={() => act.post(`/subscriptions/${subscription.id}/resume`, { preserveScroll: true })}>Resume</button>
                        ) : null}
                        {can.manage && ['active', 'paused'].includes(subscription.status) ? (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setCancelling((c) => !c)}>Cancel</button>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={subscription.status} tone={subTone(subscription.status)} />
                {subscription.collect_online ? <StatusBadge label="Collects online" tone="success" /> : null}
                {subscription.cancel_reason ? <span className="small text-body-secondary align-self-center">{subscription.cancel_reason}</span> : null}
            </div>

            {can.collect_online ? (
                <div className="card mb-3">
                    <div className="card-body d-flex flex-wrap align-items-center gap-3">
                        <div className="flex-grow-1">
                            <div className="fw-semibold">Collect online</div>
                            <p className="form-text mb-0">
                                {subscription.collect_online
                                    ? 'Every invoice this subscription raises gets a Billplz payment link overnight. Paying it settles the invoice by itself.'
                                    : 'Invoices from this subscription are paid by bank transfer and recorded by hand.'}
                            </p>
                        </div>
                        <button
                            type="button"
                            className={`btn btn-sm ${subscription.collect_online ? 'btn-outline-secondary' : 'btn-primary'}`}
                            disabled={collect.processing}
                            onClick={() => collect.post(`/subscriptions/${subscription.id}/collect-online`, { preserveScroll: true })}
                        >
                            {subscription.collect_online ? 'Switch off' : 'Switch on'}
                        </button>
                    </div>
                </div>
            ) : null}

            {paymentLinks.some((link) => link.pay_url) ? (
                <div className="card mb-3">
                    <div className="card-header">Payment links</div>
                    <ul className="list-group list-group-flush">
                        {paymentLinks
                            .filter((link) => link.pay_url)
                            .map((link) => (
                                <li key={link.invoice_id} className="list-group-item d-flex flex-wrap align-items-center gap-2">
                                    <Link href={`/invoices/${link.invoice_id}`} className="fw-semibold">{link.invoice_number}</Link>
                                    <span className="text-body-secondary small">{link.outstanding} outstanding</span>
                                    <a href={link.pay_url ?? '#'} target="_blank" rel="noreferrer" className="btn btn-sm btn-outline-primary ms-auto">Open link</a>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-outline-secondary"
                                        onClick={() => void navigator.clipboard?.writeText(link.pay_url ?? '')}
                                    >
                                        Copy
                                    </button>
                                </li>
                            ))}
                    </ul>
                </div>
            ) : null}

            {cancelling ? (
                <div className="card mb-3 border-danger">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                cancel.post(`/subscriptions/${subscription.id}/cancel`, { preserveScroll: true, onSuccess: () => setCancelling(false) })
                            }}
                        >
                            <div className="col-12 col-md-9">
                                <label className="form-label" htmlFor="reason">Why is this being cancelled?</label>
                                <input id="reason" className={`form-control ${cancel.errors.reason ? 'is-invalid' : ''}`} value={cancel.data.reason} onChange={(e) => cancel.setData('reason', e.target.value)} />
                                {cancel.errors.reason ? <div className="invalid-feedback d-block">{cancel.errors.reason}</div> : null}
                            </div>
                            <div className="col-12 col-md-3 d-grid">
                                <button type="submit" className="btn btn-danger" disabled={cancel.processing}>Cancel subscription</button>
                            </div>
                            <div className="col-12">
                                <p className="form-text mb-0">Charges already raised are left alone. Only future billing stops.</p>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="row g-3 mb-3">
                <div className="col-6 col-lg-3"><StatCard label="Each time" value={`${subscription.currency} ${Number(subscription.charge).toFixed(2)}`} hint={`${Number(subscription.quantity).toFixed(0)} × ${Number(subscription.unit_price).toFixed(2)}`} /></div>
                <div className="col-6 col-lg-3"><StatCard label="Started" value={subscription.starts_on ?? '—'} /></div>
                <div className="col-6 col-lg-3">
                    <StatCard
                        label="Next invoice"
                        value={subscription.status === 'active' ? (subscription.next_invoice_on ?? '—') : '—'}
                        hint={subscription.status === 'active' ? undefined : `Not billing while ${subscription.status}`}
                    />
                </div>
                <div className="col-6 col-lg-3"><StatCard label="Charges raised" value={String(charges.length)} /></div>
            </div>

            <div className="card">
                <div className="card-header bg-body"><h2 className="h6 mb-0">Billing history</h2></div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={charges} rowKey={(row) => row.id} emptyTitle="Nothing billed yet" />
                </div>
                <div className="card-footer bg-body small text-body-secondary">
                    One charge per period, enforced by the database. Running the billing job twice cannot invoice a
                    customer twice.
                </div>
            </div>
        </AppLayout>
    )
}
