import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'

interface Item {
    id: string
    sku: string | null
    description: string
    quantity: string
    unit_price: string
    tax_amount: string
    line_total: string
}

interface Props {
    invoice: {
        id: string
        invoice_number: string
        status: string
        customer_name: string
        customer_tax_no: string | null
        currency: string
        subtotal: string
        discount_amount: string
        tax_amount: string
        total: string
        paid_amount: string
        outstanding: string
        issued_at: string | null
        due_at: string | null
        order_id: string | null
        order_number: string | null
    }
    items: Item[]
    bankAccounts: { value: string; label: string }[]
    permissions: { record_payment: boolean; void: boolean; payment_link: boolean }
    paymentLink: string | null
}

export default function InvoiceShow({ invoice, items, bankAccounts, permissions, paymentLink }: Props) {
    const [payOpen, setPayOpen] = useState(false)
    const [voidOpen, setVoidOpen] = useState(false)

    const payment = useForm({ amount: invoice.outstanding, bank_account_id: '' })
    const voiding = useForm({ reason: '' })
    const link = useForm({})

    const columns: Column<Item>[] = [
        {
            key: 'description',
            header: 'Description',
            render: (row) => (
                <div>
                    <div>{row.description}</div>
                    {row.sku ? <div className="small text-body-secondary font-monospace">{row.sku}</div> : null}
                </div>
            ),
        },
        { key: 'qty', header: 'Qty', align: 'end', render: (row) => <span className="font-monospace">{Number(row.quantity).toFixed(2)}</span> },
        { key: 'price', header: 'Unit price', align: 'end', render: (row) => <MoneyText amount={row.unit_price} currency={invoice.currency} /> },
        { key: 'tax', header: 'Tax', align: 'end', render: (row) => <MoneyText amount={row.tax_amount} currency={invoice.currency} muted /> },
        { key: 'total', header: 'Line total', align: 'end', render: (row) => <MoneyText amount={row.line_total} currency={invoice.currency} /> },
    ]

    const settled = Number(invoice.outstanding) <= 0
    const isVoid = invoice.status === 'void'

    return (
        <AppLayout>
            <Head title={invoice.invoice_number} />

            <PageHeader
                title={invoice.invoice_number}
                subtitle={`${invoice.customer_name}${invoice.issued_at ? ` · issued ${invoice.issued_at}` : ''}`}
                actions={
                    <>
                        <Link href="/invoices" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {invoice.order_id ? (
                            <Link href={`/orders/${invoice.order_id}`} className="btn btn-sm btn-outline-primary">{invoice.order_number}</Link>
                        ) : null}
                        {permissions.record_payment && !settled && !isVoid ? (
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => setPayOpen((open) => !open)}>Record payment</button>
                        ) : null}
                        {permissions.payment_link && !settled && !isVoid ? (
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-primary"
                                disabled={link.processing}
                                onClick={() => link.post(`/invoices/${invoice.id}/payment-link`, { preserveScroll: true })}
                            >
                                {link.processing ? 'Asking Billplz…' : paymentLink ? 'Refresh payment link' : 'Request online payment'}
                            </button>
                        ) : null}
                        {permissions.void && !isVoid ? (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setVoidOpen((open) => !open)}>Void</button>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge
                    label={invoice.status.replace('_', ' ')}
                    tone={invoice.status === 'paid' ? 'success' : isVoid ? 'danger' : 'neutral'}
                />
                {invoice.due_at ? <StatusBadge label={`Due ${invoice.due_at}`} tone="info" /> : null}
            </div>

            {paymentLink && !settled && !isVoid ? (
                <div className="alert alert-info d-flex flex-wrap align-items-center gap-2">
                    <span className="fw-semibold">Online payment link</span>
                    <code className="flex-grow-1 text-break small">{paymentLink}</code>
                    <a href={paymentLink} target="_blank" rel="noreferrer" className="btn btn-sm btn-primary">Open</a>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary"
                        onClick={() => void navigator.clipboard?.writeText(paymentLink)}
                    >
                        Copy
                    </button>
                    <div className="small text-body-secondary w-100 mb-0">
                        Send this to the customer. The invoice settles itself when Billplz confirms the payment — you do
                        not need to record it by hand.
                    </div>
                </div>
            ) : null}

            {payOpen ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                payment.post(`/invoices/${invoice.id}/payments`, { preserveScroll: true, onSuccess: () => setPayOpen(false) })
                            }}
                        >
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="amount">Amount</label>
                                <input
                                    id="amount"
                                    className={`form-control text-end font-monospace ${payment.errors.amount ? 'is-invalid' : ''}`}
                                    inputMode="decimal"
                                    value={payment.data.amount}
                                    onChange={(e) => payment.setData('amount', e.target.value)}
                                />
                                {payment.errors.amount ? <div className="invalid-feedback d-block">{payment.errors.amount}</div> : null}
                            </div>
                            <div className="col-12 col-md-5">
                                <label className="form-label" htmlFor="bank_account_id">Bank account</label>
                                <select
                                    id="bank_account_id"
                                    className="form-select"
                                    value={payment.data.bank_account_id}
                                    onChange={(e) => payment.setData('bank_account_id', e.target.value)}
                                >
                                    <option value="">Not banked</option>
                                    {bankAccounts.map((account) => (
                                        <option key={account.value} value={account.value}>{account.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-4 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={payment.processing}>
                                    {payment.processing ? 'Saving…' : 'Record payment'}
                                </button>
                            </div>
                        </form>
                        <p className="form-text mb-0">A payment larger than the outstanding balance is refused by the server, not by this form.</p>
                    </div>
                </div>
            ) : null}

            {voidOpen ? (
                <div className="card mb-3 border-danger">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                voiding.post(`/invoices/${invoice.id}/void`, { preserveScroll: true, onSuccess: () => setVoidOpen(false) })
                            }}
                        >
                            <div className="col-12 col-md-9">
                                <label className="form-label" htmlFor="reason">Why is this being voided?</label>
                                <input
                                    id="reason"
                                    className={`form-control ${voiding.errors.reason ? 'is-invalid' : ''}`}
                                    value={voiding.data.reason}
                                    onChange={(e) => voiding.setData('reason', e.target.value)}
                                />
                                {voiding.errors.reason ? <div className="invalid-feedback d-block">{voiding.errors.reason}</div> : null}
                            </div>
                            <div className="col-12 col-md-3 d-grid">
                                <button type="submit" className="btn btn-outline-danger" disabled={voiding.processing}>Void invoice</button>
                            </div>
                        </form>
                        <p className="form-text mb-0">Voiding reverses the posted journal entry. The invoice itself is kept.</p>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-header bg-body"><h2 className="h6 mb-0">Lines</h2></div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={items} rowKey={(row) => row.id} emptyTitle="No lines" />
                </div>
                <div className="card-footer bg-body">
                    <dl className="row mb-0 small justify-content-end">
                        <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Subtotal</dt>
                        <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={invoice.subtotal} currency={invoice.currency} /></dd>
                        <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Tax</dt>
                        <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={invoice.tax_amount} currency={invoice.currency} muted /></dd>
                        <dt className="col-8 col-sm-9 text-end">Total</dt>
                        <dd className="col-4 col-sm-3 text-end fw-semibold mb-1"><MoneyText amount={invoice.total} currency={invoice.currency} /></dd>
                        <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Paid</dt>
                        <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={invoice.paid_amount} currency={invoice.currency} muted /></dd>
                        <dt className="col-8 col-sm-9 text-end">Outstanding</dt>
                        <dd className="col-4 col-sm-3 text-end fw-semibold mb-0"><MoneyText amount={invoice.outstanding} currency={invoice.currency} /></dd>
                    </dl>
                </div>
            </div>
        </AppLayout>
    )
}
