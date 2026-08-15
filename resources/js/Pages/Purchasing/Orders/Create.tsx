import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import FormField from '@/Components/FormField'
import SelectInput from '@/Components/SelectInput'
import TextInput from '@/Components/TextInput'
import LineEditor from '@/Components/LineEditor'
import { useAuth } from '@/Hooks/useAuth'

interface Reference {
    value: string
    label: string
}

interface VariantOption extends Reference {
    cost: string
    sku: string
    product_name: string
}

interface Line {
    product_variant_id: string
    quantity: string
    unit_cost: string
}

interface FormValues {
    supplier_id: string
    branch_id: string
    warehouse_id: string
    purchase_request_id: string
    currency: string
    expected_at: string
    note: string
    lines: Line[]
}

interface Props {
    suppliers: Reference[]
    branches: Reference[]
    warehouses: Reference[]
    variants: VariantOption[]
    seed: {
        purchase_request_id: string
        reference: string | null
        branch_id: string | null
        lines: Line[]
    } | null
}

export default function PurchaseOrderCreate({ suppliers, branches, warehouses, variants, seed }: Props) {
    const { company } = useAuth()

    const { data, setData, post, processing, errors } = useForm<FormValues>({
        supplier_id: '',
        branch_id: seed?.branch_id ?? branches[0]?.value ?? '',
        warehouse_id: warehouses[0]?.value ?? '',
        purchase_request_id: seed?.purchase_request_id ?? '',
        currency: company?.currency ?? 'MYR',
        expected_at: '',
        note: '',
        lines: seed?.lines ?? [{ product_variant_id: '', quantity: '1', unit_cost: '0' }],
    })

    const setLine = (index: number, patch: Partial<Line>) => {
        setData((previous) => ({
            ...previous,
            lines: previous.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        }))
    }

    const chooseVariant = (index: number, variantId: string) => {
        const variant = variants.find((v) => v.value === variantId)

        setLine(index, variant === undefined
            ? { product_variant_id: variantId }
            : { product_variant_id: variantId, unit_cost: variant.cost })
    }

    const total = data.lines.reduce((sum, line) => {
        const quantity = Number(line.quantity)
        const cost = Number(line.unit_cost)

        return sum + (Number.isFinite(quantity) && Number.isFinite(cost) ? quantity * cost : 0)
    }, 0)

    return (
        <AppLayout>
            <Head title="New purchase order" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/purchase-orders')
                }}
            >
                <PageHeader
                    title="New purchase order"
                    subtitle={seed?.reference ? `Raised from ${seed.reference}` : 'Unit cost here is what the three-way match compares the supplier bill against.'}
                    actions={
                        <>
                            <Link href="/purchase-orders" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Raise order'}
                            </button>
                        </>
                    }
                />

                <div className="row g-3">
                    <div className="col-12 col-lg-4">
                        <div className="card h-100">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Supplier and delivery</h2></div>
                            <div className="card-body">
                                <FormField label="Supplier" name="supplier_id" required error={errors.supplier_id}>
                                    <SelectInput
                                        name="supplier_id"
                                        value={data.supplier_id}
                                        options={suppliers}
                                        placeholder="Choose a supplier…"
                                        invalid={Boolean(errors.supplier_id)}
                                        onChange={(v) => setData('supplier_id', v)}
                                    />
                                </FormField>

                                <FormField label="Receive into" name="warehouse_id" required error={errors.warehouse_id}>
                                    <SelectInput name="warehouse_id" value={data.warehouse_id} options={warehouses} invalid={Boolean(errors.warehouse_id)} onChange={(v) => setData('warehouse_id', v)} />
                                </FormField>

                                <FormField label="Branch" name="branch_id" error={errors.branch_id}>
                                    <SelectInput name="branch_id" value={data.branch_id} options={branches} placeholder="No branch" onChange={(v) => setData('branch_id', v)} />
                                </FormField>

                                <div className="row">
                                    <div className="col-5">
                                        <FormField label="Currency" name="currency" required error={errors.currency}>
                                            <TextInput name="currency" value={data.currency} invalid={Boolean(errors.currency)} onChange={(v) => setData('currency', v.toUpperCase())} />
                                        </FormField>
                                    </div>
                                    <div className="col-7">
                                        <FormField label="Expected" name="expected_at" error={errors.expected_at}>
                                            <TextInput name="expected_at" type="date" value={data.expected_at} onChange={(v) => setData('expected_at', v)} />
                                        </FormField>
                                    </div>
                                </div>

                                <FormField label="Note" name="note" error={errors.note}>
                                    <textarea id="note" name="note" className="form-control" rows={3} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                                </FormField>
                            </div>
                        </div>
                    </div>

                    <div className="col-12 col-lg-8">
                        <LineEditor
                            title="Order lines"
                            error={errors.lines}
                            onAdd={() => setData((previous) => ({
                                ...previous,
                                lines: [...previous.lines, { product_variant_id: '', quantity: '1', unit_cost: '0' }],
                            }))}
                            footer={
                                <div className="d-flex justify-content-between align-items-center">
                                    <span className="small text-body-secondary">Order total</span>
                                    <span className="font-monospace fw-semibold">
                                        {data.currency} {total.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                    </span>
                                </div>
                            }
                        >
                            {data.lines.map((line, index) => (
                                <div key={`line-${index}`} className="row g-2 align-items-end mb-2">
                                    <div className="col-12 col-md-5">
                                        <label className="form-label small" htmlFor={`variant-${index}`}>Item</label>
                                        <select
                                            id={`variant-${index}`}
                                            className={`form-select form-select-sm ${errors[`lines.${index}.product_variant_id` as keyof typeof errors] ? 'is-invalid' : ''}`}
                                            value={line.product_variant_id}
                                            onChange={(e) => chooseVariant(index, e.target.value)}
                                        >
                                            <option value="">Choose an item…</option>
                                            {variants.map((variant) => (
                                                <option key={variant.value} value={variant.value}>{variant.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-4 col-md-2">
                                        <label className="form-label small" htmlFor={`quantity-${index}`}>Quantity</label>
                                        <input
                                            id={`quantity-${index}`}
                                            className={`form-control form-control-sm text-end font-monospace ${errors[`lines.${index}.quantity` as keyof typeof errors] ? 'is-invalid' : ''}`}
                                            inputMode="decimal"
                                            value={line.quantity}
                                            onChange={(e) => setLine(index, { quantity: e.target.value })}
                                        />
                                    </div>
                                    <div className="col-4 col-md-2">
                                        <label className="form-label small" htmlFor={`cost-${index}`}>Unit cost</label>
                                        <input
                                            id={`cost-${index}`}
                                            className={`form-control form-control-sm text-end font-monospace ${errors[`lines.${index}.unit_cost` as keyof typeof errors] ? 'is-invalid' : ''}`}
                                            inputMode="decimal"
                                            value={line.unit_cost}
                                            onChange={(e) => setLine(index, { unit_cost: e.target.value })}
                                        />
                                    </div>
                                    <div className="col-3 col-md-2 text-end">
                                        <label className="form-label small d-block">Line</label>
                                        <span className="font-monospace small">
                                            {(Number(line.quantity) * Number(line.unit_cost) || 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                        </span>
                                    </div>
                                    <div className="col-1 d-grid">
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-outline-danger"
                                            disabled={data.lines.length === 1}
                                            aria-label={`Remove line ${index + 1}`}
                                            onClick={() => setData((previous) => ({
                                                ...previous,
                                                lines: previous.lines.filter((_, i) => i !== index),
                                            }))}
                                        >
                                            <i className="bi bi-x-lg" aria-hidden="true" />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </LineEditor>
                    </div>
                </div>
            </form>
        </AppLayout>
    )
}
