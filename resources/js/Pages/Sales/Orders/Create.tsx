import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import FormField from '@/Components/FormField'
import SelectInput from '@/Components/SelectInput'
import TextInput from '@/Components/TextInput'
import { useAuth } from '@/Hooks/useAuth'

interface Reference {
    value: string
    label: string
}

interface VariantOption extends Reference {
    price: string
}

interface Line {
    variant_id: string
    quantity: string
}

interface FormValues {
    customer_id: string
    customer_name: string
    customer_phone: string
    branch_id: string
    is_cod: boolean
    lines: Line[]
}

interface Props {
    customers: Reference[]
    branches: Reference[]
    variants: VariantOption[]
}

export default function OrderCreate({ customers, branches, variants }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const { data, setData, post, processing, errors } = useForm<FormValues>({
        customer_id: '',
        customer_name: '',
        customer_phone: '',
        branch_id: branches[0]?.value ?? '',
        is_cod: false,
        lines: [{ variant_id: '', quantity: '1' }],
    })

    const priceOf = (variantId: string): string => variants.find((v) => v.value === variantId)?.price ?? '0'

    const indicativeTotal = data.lines.reduce((sum, line) => {
        const quantity = Number(line.quantity)
        const price = Number(priceOf(line.variant_id))

        return sum + (Number.isFinite(quantity) && Number.isFinite(price) ? quantity * price : 0)
    }, 0)

    const setLine = (index: number, patch: Partial<Line>) => {
        setData((previous) => ({
            ...previous,
            lines: previous.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        }))
    }

    return (
        <AppLayout>
            <Head title="New order" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/orders')
                }}
            >
                <PageHeader
                    title="New order"
                    subtitle="Prices, costs and attribution are all snapshotted the moment this order is created."
                    actions={
                        <>
                            <Link href="/orders" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Creating…' : 'Create order'}
                            </button>
                        </>
                    }
                />

                <div className="row g-3">
                    <div className="col-12 col-lg-5">
                        <div className="card h-100">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Customer</h2></div>
                            <div className="card-body">
                                <FormField label="Existing customer" name="customer_id" error={errors.customer_id} hint="Leave empty for a walk-in.">
                                    <SelectInput
                                        name="customer_id"
                                        value={data.customer_id}
                                        options={customers}
                                        placeholder="Walk-in customer"
                                        onChange={(v) => setData('customer_id', v)}
                                    />
                                </FormField>

                                {data.customer_id === '' ? (
                                    <>
                                        <FormField label="Name" name="customer_name" error={errors.customer_name}>
                                            <TextInput name="customer_name" value={data.customer_name} onChange={(v) => setData('customer_name', v)} />
                                        </FormField>
                                        <FormField label="Phone" name="customer_phone" error={errors.customer_phone}>
                                            <TextInput name="customer_phone" value={data.customer_phone} onChange={(v) => setData('customer_phone', v)} />
                                        </FormField>
                                    </>
                                ) : null}

                                <FormField label="Branch" name="branch_id" error={errors.branch_id}>
                                    <SelectInput name="branch_id" value={data.branch_id} options={branches} placeholder="No branch" onChange={(v) => setData('branch_id', v)} />
                                </FormField>

                                <div className="form-check">
                                    <input
                                        id="is_cod"
                                        className="form-check-input"
                                        type="checkbox"
                                        checked={data.is_cod}
                                        onChange={(e) => setData('is_cod', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="is_cod">Cash on delivery</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="col-12 col-lg-7">
                        <div className="card h-100">
                            <div className="card-header bg-body d-flex align-items-center justify-content-between">
                                <h2 className="h6 mb-0">Lines</h2>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    onClick={() => setData((previous) => ({ ...previous, lines: [...previous.lines, { variant_id: '', quantity: '1' }] }))}
                                >
                                    Add line
                                </button>
                            </div>
                            <div className="card-body">
                                {errors.lines ? <div className="alert alert-danger py-2">{errors.lines}</div> : null}

                                {data.lines.map((line, index) => (
                                    <div key={`line-${index}`} className="row g-2 align-items-end mb-2">
                                        <div className="col-7">
                                            <label className="form-label small" htmlFor={`variant-${index}`}>Product</label>
                                            <select
                                                id={`variant-${index}`}
                                                className={`form-select form-select-sm ${errors[`lines.${index}.variant_id` as keyof typeof errors] ? 'is-invalid' : ''}`}
                                                value={line.variant_id}
                                                onChange={(e) => setLine(index, { variant_id: e.target.value })}
                                            >
                                                <option value="">Choose a product…</option>
                                                {variants.map((variant) => (
                                                    <option key={variant.value} value={variant.value}>{variant.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-3">
                                            <label className="form-label small" htmlFor={`quantity-${index}`}>Quantity</label>
                                            <input
                                                id={`quantity-${index}`}
                                                className={`form-control form-control-sm text-end font-monospace ${errors[`lines.${index}.quantity` as keyof typeof errors] ? 'is-invalid' : ''}`}
                                                inputMode="decimal"
                                                value={line.quantity}
                                                onChange={(e) => setLine(index, { quantity: e.target.value })}
                                            />
                                        </div>
                                        <div className="col-2 d-grid">
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-danger"
                                                disabled={data.lines.length === 1}
                                                aria-label={`Remove line ${index + 1}`}
                                                onClick={() => setData((previous) => ({ ...previous, lines: previous.lines.filter((_, i) => i !== index) }))}
                                            >
                                                <i className="bi bi-x-lg" aria-hidden="true" />
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="card-footer bg-body d-flex justify-content-between align-items-center">
                                <span className="small text-body-secondary">Indicative, before tiered pricing and tax</span>
                                <span className="font-monospace">
                                    {currency} {indicativeTotal.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <p className="form-text mt-3">
                    The price on the saved order comes from the pricing engine — customer tier, price list and promotions —
                    not from the figure shown above.
                </p>
            </form>
        </AppLayout>
    )
}
