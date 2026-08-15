import { Head, Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import Pagination from '@/Components/Pagination'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

interface Option {
    value: string
    label: string
}

interface Row {
    id: string
    reference: string
    customer: string | null
    plan: string | null
    interval: string | null
    status: string
    quantity: string
    unit_price: string
    charge: string
    currency: string
    next_invoice_on: string | null
    overdue: boolean
    owner: string | null
}

interface Props {
    subscriptions: Paginated<Row>
    filters: { status: string }
    monthlyValue: string
    plans: Option[]
    customers: Option[]
    can: { manage: boolean; configure: boolean }
}

export const subTone = (status: string) =>
    status === 'active' ? 'success' : status === 'paused' ? 'warning' : status === 'cancelled' ? 'danger' : 'neutral'

export default function SubscriptionIndex({ subscriptions, filters, monthlyValue, plans, customers, can }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'
    const [open, setOpen] = useState(false)

    const form = useForm({ customer_id: '', subscription_plan_id: '', quantity: '1', starts_on: '' })

    const columns: Column<Row>[] = [
        {
            key: 'sub',
            header: 'Subscription',
            render: (row) => (
                <div>
                    <Link href={`/subscriptions/${row.id}`} className="fw-semibold text-decoration-none">{row.customer ?? 'Unknown'}</Link>
                    <div className="small text-body-secondary font-monospace">{row.reference}</div>
                </div>
            ),
        },
        { key: 'plan', header: 'Plan', render: (row) => <span>{row.plan}<span className="small text-body-secondary d-block">{row.interval}</span></span> },
        { key: 'charge', header: 'Each time', align: 'end', render: (row) => <MoneyText amount={row.charge} currency={row.currency} /> },
        {
            key: 'next',
            header: 'Next invoice',
            render: (row) =>
                row.status !== 'active'
                    ? <span className="text-body-secondary">—</span>
                    : <span className={row.overdue ? 'text-danger fw-semibold' : ''}>{row.next_invoice_on}</span>,
        },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status} tone={subTone(row.status)} /> },
    ]

    return (
        <AppLayout>
            <Head title="Subscriptions" />

            <PageHeader
                title="Subscriptions"
                subtitle="Recurring charges. Each one raises an ordinary order and invoice on its due date, so it reaches revenue the same way anything else does."
                actions={
                    <>
                        {can.configure ? <Link href="/subscription-plans" className="btn btn-sm btn-outline-secondary">Plans</Link> : null}
                        {can.manage && plans.length > 0 ? (
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>New subscription</button>
                        ) : null}
                    </>
                }
            />

            <div className="row g-3 mb-3">
                <div className="col-12 col-lg-4">
                    <StatCard
                        label="Recurring revenue a month"
                        value={`${currency} ${Number(monthlyValue).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                        hint="Active subscriptions only, normalised to a month"
                    />
                </div>
            </div>

            {plans.length === 0 ? (
                <div className="alert alert-warning">
                    No subscription plans exist yet, so nothing can be subscribed to.
                    {can.configure ? <> <Link href="/subscription-plans">Set one up</Link>.</> : ''}
                </div>
            ) : null}

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/subscriptions')
                            }}
                        >
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="customer_id">Customer</label>
                                <select id="customer_id" className={`form-select ${form.errors.customer_id ? 'is-invalid' : ''}`} value={form.data.customer_id} onChange={(e) => form.setData('customer_id', e.target.value)}>
                                    <option value="">Choose…</option>
                                    {customers.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                                </select>
                                {form.errors.customer_id ? <div className="invalid-feedback d-block">{form.errors.customer_id}</div> : null}
                            </div>
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="subscription_plan_id">Plan</label>
                                <select id="subscription_plan_id" className="form-select" value={form.data.subscription_plan_id} onChange={(e) => form.setData('subscription_plan_id', e.target.value)}>
                                    <option value="">Choose…</option>
                                    {plans.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                                </select>
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="quantity">Quantity</label>
                                <input id="quantity" className="form-control text-end font-monospace" inputMode="decimal" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="starts_on">First invoice</label>
                                <input id="starts_on" type="date" className={`form-control ${form.errors.starts_on ? 'is-invalid' : ''}`} value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                            </div>
                            <div className="col-12">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>Start subscription</button>
                                <span className="form-text ms-3">
                                    The price is taken from the plan and frozen. Changing the plan later never reprices anyone already on it.
                                </span>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-header bg-body d-flex justify-content-end">
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '12rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/subscriptions', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true, replace: true })}
                    >
                        <option value="">Any status</option>
                        {['active', 'paused', 'cancelled', 'ended'].map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                </div>
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={subscriptions.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No subscriptions"
                        emptyDescription="A subscription bills on its own schedule, without anyone remembering to raise the invoice."
                    />
                </div>
                <div className="card-footer bg-body"><Pagination meta={subscriptions} /></div>
            </div>
        </AppLayout>
    )
}
