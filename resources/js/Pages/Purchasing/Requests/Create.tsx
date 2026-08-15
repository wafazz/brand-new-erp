import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import FormField from '@/Components/FormField'
import SelectInput from '@/Components/SelectInput'
import TextInput from '@/Components/TextInput'
import LineEditor from '@/Components/LineEditor'

interface Reference {
    value: string
    label: string
}

interface VariantOption extends Reference {
    cost: string
}

interface Line {
    product_variant_id: string
    quantity: string
    note: string
}

interface FormValues {
    branch_id: string
    needed_by: string
    note: string
    lines: Line[]
}

interface Props {
    branches: Reference[]
    variants: VariantOption[]
}

export default function PurchaseRequestCreate({ branches, variants }: Props) {
    const { data, setData, post, processing, errors } = useForm<FormValues>({
        branch_id: branches[0]?.value ?? '',
        needed_by: '',
        note: '',
        lines: [{ product_variant_id: '', quantity: '1', note: '' }],
    })

    const setLine = (index: number, patch: Partial<Line>) => {
        setData((previous) => ({
            ...previous,
            lines: previous.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        }))
    }

    const indicative = data.lines.reduce((sum, line) => {
        const cost = Number(variants.find((v) => v.value === line.product_variant_id)?.cost ?? 0)
        const quantity = Number(line.quantity)

        return sum + (Number.isFinite(cost) && Number.isFinite(quantity) ? cost * quantity : 0)
    }, 0)

    return (
        <AppLayout>
            <Head title="New purchase request" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/purchase-requests')
                }}
            >
                <PageHeader
                    title="New purchase request"
                    subtitle="A request asks for goods. It commits nothing until it becomes a purchase order."
                    actions={
                        <>
                            <Link href="/purchase-requests" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Raise request'}
                            </button>
                        </>
                    }
                />

                <div className="row g-3">
                    <div className="col-12 col-lg-4">
                        <div className="card h-100">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Context</h2></div>
                            <div className="card-body">
                                <FormField label="Branch" name="branch_id" error={errors.branch_id}>
                                    <SelectInput name="branch_id" value={data.branch_id} options={branches} placeholder="No branch" onChange={(v) => setData('branch_id', v)} />
                                </FormField>

                                <FormField label="Needed by" name="needed_by" error={errors.needed_by}>
                                    <TextInput name="needed_by" type="date" value={data.needed_by} onChange={(v) => setData('needed_by', v)} />
                                </FormField>

                                <FormField label="Note" name="note" error={errors.note}>
                                    <textarea
                                        id="note"
                                        name="note"
                                        className="form-control"
                                        rows={4}
                                        value={data.note}
                                        onChange={(e) => setData('note', e.target.value)}
                                    />
                                </FormField>
                            </div>
                        </div>
                    </div>

                    <div className="col-12 col-lg-8">
                        <LineEditor
                            title="Requested items"
                            error={errors.lines}
                            onAdd={() => setData((previous) => ({
                                ...previous,
                                lines: [...previous.lines, { product_variant_id: '', quantity: '1', note: '' }],
                            }))}
                            footer={
                                <div className="d-flex justify-content-between align-items-center small">
                                    <span className="text-body-secondary">Indicative, at last known cost price</span>
                                    <span className="font-monospace">
                                        {indicative.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
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
                                            onChange={(e) => setLine(index, { product_variant_id: e.target.value })}
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
                                    <div className="col-6 col-md-4">
                                        <label className="form-label small" htmlFor={`note-${index}`}>Note</label>
                                        <input
                                            id={`note-${index}`}
                                            className="form-control form-control-sm"
                                            value={line.note}
                                            onChange={(e) => setLine(index, { note: e.target.value })}
                                        />
                                    </div>
                                    <div className="col-2 col-md-1 d-grid">
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
