import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import MoneyText from '@/Components/MoneyText'
import StatusBadge from '@/Components/StatusBadge'

interface Component {
    kind: string
    allocation: string
    pool: string
    share: string
    per_unit: string
    basis: string
}

interface Basis {
    purchase_unit_cost: string
    landed_unit_cost: string
    added_per_unit: string
    components: Component[]
    explanation: string
}

interface Item {
    id: string
    sku: string | null
    product: string | null
    quantity: string
    unit_cost: string
    landed_unit_cost: string | null
    landed_cost_basis: Basis | null
}

interface Cost {
    id: string
    kind: string
    allocation: string
    amount: string
    note: string | null
}

interface Props {
    receipt: {
        id: string
        reference: string | null
        supplier_do_number: string | null
        purchase_order_id: string | null
        purchase_order: string | null
        supplier: string | null
        warehouse: string | null
        receiver: string | null
        received_at: string | null
        currency: string
        note: string | null
    }
    items: Item[]
    costs: Cost[]
    permissions: { add_cost: boolean }
}

export default function GoodsReceiptShow({ receipt, items, costs, permissions }: Props) {
    const addCost = useForm({ kind: 'freight', allocation: 'by_value', amount: '', note: '' })

    const columns: Column<Item>[] = [
        {
            key: 'item',
            header: 'Item',
            render: (row) => (
                <div>
                    <div>{row.product ?? 'Unknown item'}</div>
                    <div className="small text-body-secondary font-monospace">{row.sku ?? '—'}</div>
                </div>
            ),
        },
        { key: 'quantity', header: 'Received', align: 'end', render: (row) => <span className="font-monospace">{Number(row.quantity).toFixed(2)}</span> },
        { key: 'unit_cost', header: 'Purchase cost', align: 'end', render: (row) => <MoneyText amount={row.unit_cost} currency={receipt.currency} muted /> },
        {
            key: 'landed',
            header: 'Landed cost',
            align: 'end',
            render: (row) =>
                row.landed_unit_cost === null
                    ? <span className="text-body-secondary">—</span>
                    : <MoneyText amount={row.landed_unit_cost} currency={receipt.currency} />,
        },
    ]

    const costColumns: Column<Cost>[] = [
        { key: 'kind', header: 'Kind', render: (row) => row.kind },
        { key: 'allocation', header: 'Apportioned', render: (row) => row.allocation.replace(/_/g, ' ') },
        { key: 'amount', header: 'Amount', align: 'end', render: (row) => <MoneyText amount={row.amount} currency={receipt.currency} /> },
        { key: 'note', header: 'Note', render: (row) => row.note ?? '—' },
    ]

    return (
        <AppLayout>
            <Head title={receipt.reference ?? 'Goods receipt'} />

            <PageHeader
                title={receipt.reference ?? 'Goods receipt'}
                subtitle={`${receipt.supplier ?? 'Unknown supplier'}${receipt.warehouse ? ` · into ${receipt.warehouse}` : ''}${receipt.received_at ? ` · ${receipt.received_at}` : ''}`}
                actions={
                    <>
                        {receipt.purchase_order_id ? (
                            <Link href={`/purchase-orders/${receipt.purchase_order_id}`} className="btn btn-sm btn-outline-secondary">
                                {receipt.purchase_order}
                            </Link>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                {receipt.supplier_do_number ? <StatusBadge label={`DO ${receipt.supplier_do_number}`} tone="info" /> : null}
                {receipt.receiver ? <StatusBadge label={`Received by ${receipt.receiver}`} tone="neutral" /> : null}
            </div>

            <div className="row g-3">
                <div className="col-12 col-xl-8">
                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Lines</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} rows={items} rowKey={(row) => row.id} emptyTitle="No lines" />
                        </div>
                    </div>

                    {items.some((item) => item.landed_cost_basis !== null) ? (
                        <div className="card">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">How the landed cost was apportioned</h2></div>
                            <div className="card-body">
                                {items.filter((item) => item.landed_cost_basis !== null).map((item) => (
                                    <div key={item.id} className="mb-4">
                                        <div className="fw-semibold small font-monospace mb-1">{item.sku ?? '—'}</div>
                                        <p className="small mb-2">{item.landed_cost_basis?.explanation}</p>

                                        {(item.landed_cost_basis?.components ?? []).length > 0 ? (
                                            <div className="table-responsive">
                                                <table className="table table-sm small mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th scope="col">Cost</th>
                                                            <th scope="col">Basis</th>
                                                            <th scope="col" className="text-end">Pool</th>
                                                            <th scope="col" className="text-end">This line</th>
                                                            <th scope="col" className="text-end">Per unit</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {(item.landed_cost_basis?.components ?? []).map((component, index) => (
                                                            <tr key={`${item.id}-${index}`}>
                                                                <td>{component.kind}</td>
                                                                <td className="text-body-secondary">{component.basis}</td>
                                                                <td className="text-end font-monospace">{Number(component.pool).toFixed(2)}</td>
                                                                <td className="text-end font-monospace">{Number(component.share).toFixed(2)}</td>
                                                                <td className="text-end font-monospace">{Number(component.per_unit).toFixed(4)}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                            <div className="card-footer bg-body small text-body-secondary">
                                This breakdown is stored on the receipt line, not recomputed for display. It is what the
                                average cost on the variant was actually built from.
                            </div>
                        </div>
                    ) : null}
                </div>

                <div className="col-12 col-xl-4">
                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Landed costs</h2></div>
                        <div className="card-body p-0">
                            <DataTable
                                columns={costColumns}
                                rows={costs}
                                rowKey={(row) => row.id}
                                emptyTitle="No landed cost"
                                emptyDescription="Freight, duty and handling go here. Without them the average cost is only the purchase price."
                            />
                        </div>
                    </div>

                    {permissions.add_cost ? (
                        <div className="card">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Add a landed cost</h2></div>
                            <div className="card-body">
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault()
                                        addCost.post(`/goods-receipts/${receipt.id}/costs`, {
                                            preserveScroll: true,
                                            onSuccess: () => addCost.reset('amount', 'note'),
                                        })
                                    }}
                                >
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="kind">Kind</label>
                                        <select id="kind" className="form-select" value={addCost.data.kind} onChange={(e) => addCost.setData('kind', e.target.value)}>
                                            {['freight', 'duty', 'insurance', 'handling', 'other'].map((kind) => (
                                                <option key={kind} value={kind}>{kind}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="allocation">Apportion</label>
                                        <select id="allocation" className="form-select" value={addCost.data.allocation} onChange={(e) => addCost.setData('allocation', e.target.value)}>
                                            <option value="by_value">By line value</option>
                                            <option value="by_quantity">By quantity</option>
                                            <option value="by_weight">By weight</option>
                                        </select>
                                        <div className="form-text">By weight needs a weight on the variant, or nothing is apportioned.</div>
                                    </div>

                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="amount">Amount</label>
                                        <input
                                            id="amount"
                                            className={`form-control text-end font-monospace ${addCost.errors.amount ? 'is-invalid' : ''}`}
                                            inputMode="decimal"
                                            value={addCost.data.amount}
                                            onChange={(e) => addCost.setData('amount', e.target.value)}
                                        />
                                        {addCost.errors.amount ? <div className="invalid-feedback d-block">{addCost.errors.amount}</div> : null}
                                    </div>

                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="note">Note</label>
                                        <input id="note" className="form-control" value={addCost.data.note} onChange={(e) => addCost.setData('note', e.target.value)} />
                                    </div>

                                    <button type="submit" className="btn btn-primary w-100" disabled={addCost.processing}>
                                        {addCost.processing ? 'Apportioning…' : 'Add and apportion'}
                                    </button>
                                    <p className="form-text mb-0">
                                        Adding a cost recomputes every line on this receipt and the average cost of each
                                        variant from its full receipt history.
                                    </p>
                                </form>
                            </div>
                        </div>
                    ) : null}
                </div>
            </div>
        </AppLayout>
    )
}
