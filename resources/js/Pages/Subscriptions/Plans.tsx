import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'

interface Option {
    value: string
    label: string
}

interface Plan {
    id: string
    code: string
    name: string
    interval: string
    price: string
    currency: string
    sku: string | null
    is_active: boolean
    subscribers: number
}

interface Props {
    plans: Plan[]
    variants: Option[]
    intervals: Option[]
}

export default function SubscriptionPlans({ plans, variants, intervals }: Props) {
    const [open, setOpen] = useState(false)

    const form = useForm({
        product_variant_id: '',
        code: '',
        name: '',
        interval: intervals[0]?.value ?? 'monthly',
        price: '0',
        currency: 'MYR',
    })

    const columns: Column<Plan>[] = [
        {
            key: 'plan',
            header: 'Plan',
            render: (row) => (
                <div>
                    <span className="fw-semibold">{row.name}</span>
                    <div className="small text-body-secondary font-monospace">{row.code} · sells {row.sku ?? '—'}</div>
                </div>
            ),
        },
        { key: 'interval', header: 'Every', render: (row) => row.interval },
        { key: 'price', header: 'Price', align: 'end', render: (row) => <MoneyText amount={row.price} currency={row.currency} /> },
        { key: 'subscribers', header: 'Subscribers', align: 'end', render: (row) => String(row.subscribers) },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.is_active ? 'offered' : 'retired'} tone={row.is_active ? 'success' : 'neutral'} />,
        },
    ]

    return (
        <AppLayout>
            <Head title="Subscription plans" />

            <PageHeader
                title="Subscription plans"
                subtitle="What customers can subscribe to, and how often it charges."
                actions={
                    <>
                        <Link href="/subscriptions" className="btn btn-sm btn-outline-secondary">Subscriptions</Link>
                        <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>New plan</button>
                    </>
                }
            />

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/subscription-plans', { onSuccess: () => { form.reset(); setOpen(false) } })
                            }}
                        >
                            <div className="col-12 col-md-3">
                                <label className="form-label" htmlFor="name">Name</label>
                                <input id="name" className={`form-control ${form.errors.name ? 'is-invalid' : ''}`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                {form.errors.name ? <div className="invalid-feedback d-block">{form.errors.name}</div> : null}
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="code">Code</label>
                                <input id="code" className={`form-control ${form.errors.code ? 'is-invalid' : ''}`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-3">
                                <label className="form-label" htmlFor="product_variant_id">Sells</label>
                                <select id="product_variant_id" className={`form-select ${form.errors.product_variant_id ? 'is-invalid' : ''}`} value={form.data.product_variant_id} onChange={(e) => form.setData('product_variant_id', e.target.value)}>
                                    <option value="">Choose a product…</option>
                                    {variants.map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}
                                </select>
                                {form.errors.product_variant_id ? <div className="invalid-feedback d-block">{form.errors.product_variant_id}</div> : null}
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="interval">Every</label>
                                <select id="interval" className="form-select" value={form.data.interval} onChange={(e) => form.setData('interval', e.target.value)}>
                                    {intervals.map((i) => <option key={i.value} value={i.value}>{i.label}</option>)}
                                </select>
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="price">Price</label>
                                <input id="price" className="form-control text-end font-monospace" inputMode="decimal" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />
                            </div>
                            <div className="col-12">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>Add plan</button>
                                <span className="form-text ms-3">
                                    A plan sells a product, so a subscription charge reaches stock, revenue and commission
                                    exactly like any other sale.
                                </span>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={plans}
                        rowKey={(row) => row.id}
                        emptyTitle="No plans yet"
                        emptyDescription="Until a plan exists, nothing can be subscribed to."
                    />
                </div>
                <div className="card-footer bg-body small text-body-secondary">
                    Changing a plan's price never reprices anyone already subscribed — their price was frozen when they
                    signed up.
                </div>
            </div>
        </AppLayout>
    )
}
