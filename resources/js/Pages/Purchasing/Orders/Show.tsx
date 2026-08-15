import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import ApprovalTrail, { type ApprovalPanel } from '@/Components/ApprovalTrail'
import { orderTone } from './Index'

interface Item {
    id: string
    sku: string | null
    product_name: string | null
    quantity: string
    quantity_received: string
    outstanding: string
    unit_cost: string
    line_total: string
}

interface Receipt {
    id: string
    reference: string | null
    supplier_do_number: string | null
    items_count: number
    received_at: string | null
}

interface Bill {
    id: string
    reference: string | null
    supplier_invoice_number: string | null
    status: string
    total: string
}

interface Props {
    order: {
        id: string
        reference: string | null
        status: string
        supplier_id: string | null
        supplier: string | null
        branch: string | null
        warehouse: string | null
        currency: string
        subtotal: string
        tax_amount: string
        total: string
        expected_at: string | null
        note: string | null
    }
    items: Item[]
    receipts: Receipt[]
    bills: Bill[]
    approval: ApprovalPanel | null
    warehouses: { value: string; label: string }[]
    permissions: { submit: boolean; approve: boolean; receive: boolean; bill: boolean }
}

const qty = (value: string) => Number(value).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function PurchaseOrderShow({ order, items, receipts, bills, approval, warehouses, permissions }: Props) {
    const [decidingOpen, setDecidingOpen] = useState(false)
    const [receivingOpen, setReceivingOpen] = useState(false)

    const submit = useForm({})
    const decide = useForm({ decision: 'approved', comment: '' })
    const receive = useForm({
        warehouse_id: warehouses[0]?.value ?? '',
        supplier_do_number: '',
        lines: items.map((item) => ({ purchase_order_item_id: item.id, quantity: item.outstanding })),
    })

    const receivable = ['approved', 'partially_received'].includes(order.status)
    const outstandingLines = items.filter((item) => Number(item.outstanding) > 0)

    const itemColumns: Column<Item>[] = [
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
        { key: 'ordered', header: 'Ordered', align: 'end', render: (row) => <span className="font-monospace">{qty(row.quantity)}</span> },
        { key: 'received', header: 'Received', align: 'end', render: (row) => <span className="font-monospace text-body-secondary">{qty(row.quantity_received)}</span> },
        {
            key: 'outstanding',
            header: 'Outstanding',
            align: 'end',
            render: (row) => (
                <span className={`font-monospace ${Number(row.outstanding) > 0 ? 'text-warning' : 'text-success'}`}>{qty(row.outstanding)}</span>
            ),
        },
        { key: 'cost', header: 'Unit cost', align: 'end', render: (row) => <MoneyText amount={row.unit_cost} currency={order.currency} /> },
        { key: 'total', header: 'Line total', align: 'end', render: (row) => <MoneyText amount={row.line_total} currency={order.currency} /> },
    ]

    const receiptColumns: Column<Receipt>[] = [
        {
            key: 'reference',
            header: 'Receipt',
            render: (row) => <Link href={`/goods-receipts/${row.id}`} className="font-monospace text-decoration-none">{row.reference ?? '—'}</Link>,
        },
        { key: 'do', header: 'Supplier DO', render: (row) => row.supplier_do_number ?? '—' },
        { key: 'lines', header: 'Lines', align: 'end', render: (row) => String(row.items_count) },
        { key: 'at', header: 'Received', render: (row) => row.received_at ?? '—' },
    ]

    const billColumns: Column<Bill>[] = [
        {
            key: 'reference',
            header: 'Bill',
            render: (row) => <Link href={`/supplier-bills/${row.id}`} className="font-monospace text-decoration-none">{row.reference ?? '—'}</Link>,
        },
        { key: 'invoice', header: 'Supplier invoice', render: (row) => row.supplier_invoice_number ?? '—' },
        { key: 'total', header: 'Total', align: 'end', render: (row) => <MoneyText amount={row.total} currency={order.currency} /> },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status} tone={row.status === 'paid' ? 'success' : row.status === 'disputed' ? 'danger' : 'neutral'} /> },
    ]

    return (
        <AppLayout>
            <Head title={order.reference ?? 'Purchase order'} />

            <PageHeader
                title={order.reference ?? 'Purchase order'}
                subtitle={`${order.supplier ?? 'Unknown supplier'}${order.warehouse ? ` · into ${order.warehouse}` : ''}`}
                actions={
                    <>
                        <Link href="/purchase-orders" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {permissions.submit && order.status === 'draft' ? (
                            <button
                                type="button"
                                className="btn btn-sm btn-primary"
                                disabled={submit.processing}
                                onClick={() => submit.post(`/purchase-orders/${order.id}/submit`, { preserveScroll: true })}
                            >
                                Submit for approval
                            </button>
                        ) : null}
                        {permissions.approve && order.status === 'pending' ? (
                            <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setDecidingOpen((open) => !open)}>Decide</button>
                        ) : null}
                        {permissions.receive && receivable && outstandingLines.length > 0 ? (
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => setReceivingOpen((open) => !open)}>Receive goods</button>
                        ) : null}
                        {permissions.bill && ['partially_received', 'received', 'billed'].includes(order.status) ? (
                            <Link href={`/purchase-orders/${order.id}/bills/create`} className="btn btn-sm btn-outline-primary">Record bill</Link>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={order.status.replace(/_/g, ' ')} tone={orderTone(order.status)} />
                {order.expected_at ? <StatusBadge label={`Expected ${order.expected_at}`} tone="info" /> : null}
            </div>

            {decidingOpen ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                decide.post(`/purchase-orders/${order.id}/decide`, { preserveScroll: true, onSuccess: () => setDecidingOpen(false) })
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
                    </div>
                </div>
            ) : null}

            {receivingOpen ? (
                <div className="card mb-3 border-primary">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Receive goods</h2></div>
                    <div className="card-body">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault()
                                receive.transform((current) => ({
                                    ...current,
                                    lines: current.lines.filter((line) => Number(line.quantity) > 0),
                                }))
                                receive.post(`/purchase-orders/${order.id}/receipts`, { preserveScroll: true })
                            }}
                        >
                            <div className="row g-2 mb-3">
                                <div className="col-12 col-md-4">
                                    <label className="form-label" htmlFor="warehouse_id">Warehouse</label>
                                    <select id="warehouse_id" className="form-select" value={receive.data.warehouse_id} onChange={(e) => receive.setData('warehouse_id', e.target.value)}>
                                        {warehouses.map((warehouse) => (
                                            <option key={warehouse.value} value={warehouse.value}>{warehouse.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="col-12 col-md-4">
                                    <label className="form-label" htmlFor="supplier_do_number">Supplier DO number</label>
                                    <input
                                        id="supplier_do_number"
                                        className="form-control"
                                        value={receive.data.supplier_do_number}
                                        onChange={(e) => receive.setData('supplier_do_number', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="table-responsive">
                                <table className="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">Item</th>
                                            <th scope="col" className="text-end">Outstanding</th>
                                            <th scope="col" className="text-end" style={{ width: '10rem' }}>Receiving now</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {items.map((item, index) => (
                                            <tr key={item.id}>
                                                <td>
                                                    <div>{item.product_name ?? 'Unknown item'}</div>
                                                    <div className="small text-body-secondary font-monospace">{item.sku ?? '—'}</div>
                                                </td>
                                                <td className="text-end font-monospace">{qty(item.outstanding)}</td>
                                                <td>
                                                    <input
                                                        className="form-control form-control-sm text-end font-monospace"
                                                        inputMode="decimal"
                                                        aria-label={`Quantity received for ${item.sku ?? 'line'}`}
                                                        value={receive.data.lines[index]?.quantity ?? '0'}
                                                        onChange={(e) => receive.setData('lines', receive.data.lines.map((line, i) => (
                                                            i === index ? { ...line, quantity: e.target.value } : line
                                                        )))}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="d-flex justify-content-between align-items-center mt-3">
                                <p className="form-text mb-0">
                                    Receiving more than a line still has outstanding is refused by the server, and stock
                                    moves in the same transaction as the receipt.
                                </p>
                                <button type="submit" className="btn btn-primary" disabled={receive.processing}>
                                    {receive.processing ? 'Receiving…' : 'Record receipt'}
                                </button>
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
                                <dt className="col-8 col-sm-9 text-end fw-normal text-body-secondary">Tax</dt>
                                <dd className="col-4 col-sm-3 text-end mb-1"><MoneyText amount={order.tax_amount} currency={order.currency} muted /></dd>
                                <dt className="col-8 col-sm-9 text-end">Total</dt>
                                <dd className="col-4 col-sm-3 text-end fw-semibold mb-0"><MoneyText amount={order.total} currency={order.currency} /></dd>
                            </dl>
                        </div>
                    </div>

                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Goods receipts</h2></div>
                        <div className="card-body p-0">
                            <DataTable
                                columns={receiptColumns}
                                rows={receipts}
                                rowKey={(row) => row.id}
                                emptyTitle="Nothing received yet"
                                emptyDescription="Receipts appear here once goods arrive against this order."
                            />
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Supplier bills</h2></div>
                        <div className="card-body p-0">
                            <DataTable
                                columns={billColumns}
                                rows={bills}
                                rowKey={(row) => row.id}
                                emptyTitle="Not billed yet"
                                emptyDescription="A bill is matched against this order and what was actually received."
                            />
                        </div>
                    </div>
                </div>

                <div className="col-12 col-xl-4">
                    <ApprovalTrail
                        approval={approval}
                        emptyNote="No approval flow is configured for purchase orders, so a decision here is recorded directly against the order."
                    />

                    {order.note ? (
                        <div className="card mt-3">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Note</h2></div>
                            <div className="card-body small text-body-secondary">{order.note}</div>
                        </div>
                    ) : null}
                </div>
            </div>
        </AppLayout>
    )
}
