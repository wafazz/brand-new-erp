import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'

interface OrderLine {
    purchase_order_item_id: string
    sku: string | null
    product_name: string | null
    ordered: string
    received: string
    already_billed: string
    unit_cost: string
}

interface Line {
    purchase_order_item_id: string
    quantity: string
    unit_cost: string
}

interface Props {
    order: { id: string; reference: string | null; supplier: string | null; currency: string }
    lines: OrderLine[]
}

const qty = (value: string) => Number(value).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function SupplierBillCreate({ order, lines }: Props) {
    const { data, setData, post, processing, errors } = useForm<{
        supplier_invoice_number: string
        billed_at: string
        due_at: string
        tax_amount: string
        lines: Line[]
    }>({
        supplier_invoice_number: '',
        billed_at: '',
        due_at: '',
        tax_amount: '0',
        lines: lines.map((line) => ({
            purchase_order_item_id: line.purchase_order_item_id,
            quantity: line.received,
            unit_cost: line.unit_cost,
        })),
    })

    const subtotal = data.lines.reduce((sum, line) => {
        const value = Number(line.quantity) * Number(line.unit_cost)

        return sum + (Number.isFinite(value) ? value : 0)
    }, 0)

    const total = subtotal + (Number(data.tax_amount) || 0)

    return (
        <AppLayout>
            <Head title="Record a supplier bill" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post(`/purchase-orders/${order.id}/bills`)
                }}
            >
                <PageHeader
                    title="Record a supplier bill"
                    subtitle={`${order.reference ?? 'Purchase order'} · ${order.supplier ?? 'Unknown supplier'}`}
                    actions={
                        <>
                            <Link href={`/purchase-orders/${order.id}`} className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Record bill'}
                            </button>
                        </>
                    }
                />

                <div className="row g-3">
                    <div className="col-12 col-lg-4">
                        <div className="card h-100">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Bill details</h2></div>
                            <div className="card-body">
                                <FormField label="Supplier invoice number" name="supplier_invoice_number" required error={errors.supplier_invoice_number}>
                                    <TextInput
                                        name="supplier_invoice_number"
                                        value={data.supplier_invoice_number}
                                        invalid={Boolean(errors.supplier_invoice_number)}
                                        onChange={(v) => setData('supplier_invoice_number', v)}
                                    />
                                </FormField>

                                <FormField label="Billed on" name="billed_at" required error={errors.billed_at}>
                                    <TextInput name="billed_at" type="date" value={data.billed_at} invalid={Boolean(errors.billed_at)} onChange={(v) => setData('billed_at', v)} />
                                </FormField>

                                <FormField label="Due" name="due_at" error={errors.due_at}>
                                    <TextInput name="due_at" type="date" value={data.due_at} onChange={(v) => setData('due_at', v)} />
                                </FormField>

                                <FormField label="Tax" name="tax_amount" error={errors.tax_amount}>
                                    <TextInput name="tax_amount" value={data.tax_amount} onChange={(v) => setData('tax_amount', v)} />
                                </FormField>
                            </div>
                        </div>
                    </div>

                    <div className="col-12 col-lg-8">
                        <div className="card h-100">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Billed lines</h2></div>
                            <div className="card-body">
                                {errors.lines ? <div className="alert alert-danger py-2">{errors.lines}</div> : null}

                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col">Item</th>
                                                <th scope="col" className="text-end">Ordered</th>
                                                <th scope="col" className="text-end">Received</th>
                                                <th scope="col" className="text-end" style={{ width: '8rem' }}>Billing</th>
                                                <th scope="col" className="text-end" style={{ width: '9rem' }}>Unit cost</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {lines.map((line, index) => (
                                                <tr key={line.purchase_order_item_id}>
                                                    <td>
                                                        <div>{line.product_name ?? 'Unknown item'}</div>
                                                        <div className="small text-body-secondary font-monospace">{line.sku ?? '—'}</div>
                                                    </td>
                                                    <td className="text-end font-monospace text-body-secondary">{qty(line.ordered)}</td>
                                                    <td className="text-end font-monospace">{qty(line.received)}</td>
                                                    <td>
                                                        <input
                                                            className="form-control form-control-sm text-end font-monospace"
                                                            inputMode="decimal"
                                                            aria-label={`Quantity billed for ${line.sku ?? 'line'}`}
                                                            value={data.lines[index]?.quantity ?? '0'}
                                                            onChange={(e) => setData('lines', data.lines.map((l, i) => (i === index ? { ...l, quantity: e.target.value } : l)))}
                                                        />
                                                    </td>
                                                    <td>
                                                        <input
                                                            className="form-control form-control-sm text-end font-monospace"
                                                            inputMode="decimal"
                                                            aria-label={`Unit cost billed for ${line.sku ?? 'line'}`}
                                                            value={data.lines[index]?.unit_cost ?? '0'}
                                                            onChange={(e) => setData('lines', data.lines.map((l, i) => (i === index ? { ...l, unit_cost: e.target.value } : l)))}
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <p className="form-text mb-0">
                                    Enter what the supplier actually billed, not what you expected. A quantity above what
                                    was received, or a price different from the order, is what the three-way match is for.
                                </p>
                            </div>
                            <div className="card-footer bg-body">
                                <dl className="row mb-0 small justify-content-end">
                                    <dt className="col-8 text-end fw-normal text-body-secondary">Subtotal</dt>
                                    <dd className="col-4 text-end font-monospace mb-1">
                                        {order.currency} {subtotal.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                    </dd>
                                    <dt className="col-8 text-end">Total</dt>
                                    <dd className="col-4 text-end font-monospace fw-semibold mb-0">
                                        {order.currency} {total.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AppLayout>
    )
}
