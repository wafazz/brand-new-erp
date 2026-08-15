import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import { billTone } from './Index'

interface Item {
    id: string
    sku: string | null
    product_name: string | null
    ordered_unit_cost: string | null
    quantity: string
    unit_cost: string
    line_total: string
}

interface Payment {
    id: string
    method: string
    reference: string | null
    amount: string
    payer: string
    paid_at: string | null
}

interface Discrepancy {
    sku: string
    kind: string
    reason: string
}

interface Props {
    bill: {
        id: string
        reference: string | null
        supplier_invoice_number: string | null
        supplier: string | null
        purchase_order_id: string | null
        purchase_order: string | null
        status: string
        currency: string
        subtotal: string
        tax_amount: string
        total: string
        paid_amount: string
        outstanding: string
        billed_at: string | null
        due_at: string | null
    }
    items: Item[]
    match: { matched: boolean; reason: string | null; discrepancies: Discrepancy[] }
    payments: Payment[]
    permissions: { approve: boolean; pay: boolean }
}

export default function SupplierBillShow({ bill, items, match, payments, permissions }: Props) {
    const [payOpen, setPayOpen] = useState(false)

    const approve = useForm({})
    const pay = useForm({ amount: bill.outstanding, method: 'bank_transfer', reference: '' })

    const columns: Column<Item>[] = [
        {
            key: 'item',
            header: 'Item',
            render: (row) => (
                <div>
                    <div>{row.product_name ?? 'Unknown item'}</div>
                    <div className="small text-body-secondary font-monospace">{row.sku ?? '—'}</div>
                </div>
            ),
        },
        { key: 'quantity', header: 'Billed', align: 'end', render: (row) => <span className="font-monospace">{Number(row.quantity).toFixed(2)}</span> },
        {
            key: 'ordered_cost',
            header: 'Ordered at',
            align: 'end',
            render: (row) =>
                row.ordered_unit_cost === null
                    ? <span className="text-body-secondary">—</span>
                    : <MoneyText amount={row.ordered_unit_cost} currency={bill.currency} muted />,
        },
        {
            key: 'unit_cost',
            header: 'Billed at',
            align: 'end',
            render: (row) => {
                const varies = row.ordered_unit_cost !== null && Number(row.ordered_unit_cost) !== Number(row.unit_cost)

                return (
                    <span className={varies ? 'text-danger fw-semibold' : ''}>
                        <MoneyText amount={row.unit_cost} currency={bill.currency} />
                    </span>
                )
            },
        },
        { key: 'line_total', header: 'Line total', align: 'end', render: (row) => <MoneyText amount={row.line_total} currency={bill.currency} /> },
    ]

    const paymentColumns: Column<Payment>[] = [
        { key: 'at', header: 'When', render: (row) => row.paid_at ?? '—' },
        { key: 'method', header: 'Method', render: (row) => row.method.replace(/_/g, ' ') },
        { key: 'reference', header: 'Reference', render: (row) => row.reference ?? '—' },
        { key: 'amount', header: 'Amount', align: 'end', render: (row) => <MoneyText amount={row.amount} currency={bill.currency} /> },
        { key: 'payer', header: 'By', render: (row) => row.payer },
    ]

    const approvable = ['draft', 'matched', 'disputed'].includes(bill.status)

    return (
        <AppLayout>
            <Head title={bill.reference ?? 'Supplier bill'} />

            <PageHeader
                title={bill.reference ?? 'Supplier bill'}
                subtitle={`${bill.supplier ?? 'Unknown supplier'}${bill.supplier_invoice_number ? ` · ${bill.supplier_invoice_number}` : ''}`}
                actions={
                    <>
                        <Link href="/supplier-bills" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {bill.purchase_order_id ? (
                            <Link href={`/purchase-orders/${bill.purchase_order_id}`} className="btn btn-sm btn-outline-primary">{bill.purchase_order}</Link>
                        ) : null}
                        {permissions.approve && approvable ? (
                            <button
                                type="button"
                                className="btn btn-sm btn-primary"
                                disabled={approve.processing}
                                onClick={() => approve.post(`/supplier-bills/${bill.id}/approve`, { preserveScroll: true })}
                            >
                                Match and approve
                            </button>
                        ) : null}
                        {permissions.pay && bill.status === 'approved' && Number(bill.outstanding) > 0 ? (
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => setPayOpen((open) => !open)}>Pay</button>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={bill.status} tone={billTone(bill.status)} />
                {bill.due_at ? <StatusBadge label={`Due ${bill.due_at}`} tone="info" /> : null}
            </div>

            <div className={`alert ${match.matched ? 'alert-success' : 'alert-warning'}`}>
                <div className="fw-semibold mb-1">
                    {match.matched ? 'Three-way match clean' : 'Three-way match found problems'}
                </div>
                {match.matched ? (
                    <p className="mb-0 small">
                        Every billed line belongs to this order, is within what was received, and is priced as ordered.
                    </p>
                ) : (
                    <ul className="mb-0 small">
                        {match.discrepancies.map((discrepancy, index) => (
                            <li key={`${discrepancy.sku}-${index}`}>
                                <span className="font-monospace">{discrepancy.kind.replace(/_/g, ' ')}</span> — {discrepancy.reason}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {payOpen ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                pay.post(`/supplier-bills/${bill.id}/payments`, { preserveScroll: true, onSuccess: () => setPayOpen(false) })
                            }}
                        >
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="amount">Amount</label>
                                <input
                                    id="amount"
                                    className={`form-control text-end font-monospace ${pay.errors.amount ? 'is-invalid' : ''}`}
                                    inputMode="decimal"
                                    value={pay.data.amount}
                                    onChange={(e) => pay.setData('amount', e.target.value)}
                                />
                                {pay.errors.amount ? <div className="invalid-feedback d-block">{pay.errors.amount}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="method">Method</label>
                                <select id="method" className="form-select" value={pay.data.method} onChange={(e) => pay.setData('method', e.target.value)}>
                                    {['bank_transfer', 'cash', 'cheque', 'card'].map((method) => (
                                        <option key={method} value={method}>{method.replace(/_/g, ' ')}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="reference">Reference</label>
                                <input id="reference" className="form-control" value={pay.data.reference} onChange={(e) => pay.setData('reference', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={pay.processing}>Record</button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="row g-3">
                <div className="col-12 col-xl-8">
                    <div className="card">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Billed lines</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} rows={items} rowKey={(row) => row.id} emptyTitle="No lines" />
                        </div>
                        <div className="card-footer bg-body">
                            <dl className="row mb-0 small justify-content-end">
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Subtotal</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={bill.subtotal} currency={bill.currency} /></dd>
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Tax</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={bill.tax_amount} currency={bill.currency} muted /></dd>
                                <dt className="col-8 col-sm-9 text-end">Total</dt>
                                <dd className="col-4 col-sm-3 text-end fw-semibold mb-1"><MoneyText amount={bill.total} currency={bill.currency} /></dd>
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Paid</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={bill.paid_amount} currency={bill.currency} muted /></dd>
                                <dt className="col-8 col-sm-9 text-end">Outstanding</dt>
                                <dd className="col-4 col-sm-3 text-end fw-semibold mb-0"><MoneyText amount={bill.outstanding} currency={bill.currency} /></dd>
                            </dl>
                        </div>
                    </div>
                </div>

                <div className="col-12 col-xl-4">
                    <div className="card">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Payments</h2></div>
                        <div className="card-body p-0">
                            <DataTable
                                columns={paymentColumns}
                                rows={payments}
                                rowKey={(row) => row.id}
                                emptyTitle="Not paid yet"
                                emptyDescription="A bill can only be paid once it has been matched and approved."
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
