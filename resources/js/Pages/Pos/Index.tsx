import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import { useAuth } from '@/Hooks/useAuth'

interface Register {
    id: string
    code: string
    name: string
    branch: string | null
    warehouse: string | null
    busy: boolean
}

interface Recent {
    id: string
    reference: string
    register: string | null
    cashier: string | null
    status: string
    variance: string | null
    opened_at: string | null
}

interface Props {
    openSession: { id: string; reference: string; register: string | null; sales_total: string } | null
    registers: Register[]
    recent: Recent[]
    can: { sell: boolean }
}

export default function PosIndex({ openSession, registers, recent, can }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'
    const form = useForm({ pos_register_id: '', opening_float: '0' })

    const columns: Column<Recent>[] = [
        {
            key: 'reference',
            header: 'Session',
            render: (row) => (
                <div>
                    <Link href={`/pos/${row.id}`} className="fw-semibold text-decoration-none font-monospace">{row.reference}</Link>
                    <div className="small text-body-secondary">{row.opened_at}</div>
                </div>
            ),
        },
        { key: 'register', header: 'Register', render: (row) => row.register ?? '—' },
        { key: 'cashier', header: 'Cashier', render: (row) => row.cashier ?? '—' },
        {
            key: 'variance',
            header: 'Variance',
            align: 'end',
            render: (row) =>
                row.variance === null
                    ? <span className="text-body-secondary">—</span>
                    : Number(row.variance) === 0
                        ? <span className="text-success">balanced</span>
                        : <MoneyText amount={row.variance} currency={currency} />,
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.status} tone={row.status === 'open' ? 'success' : 'neutral'} />,
        },
    ]

    return (
        <AppLayout>
            <Head title="Point of sale" />

            <PageHeader
                title="Point of sale"
                subtitle="A counter sale is an ordinary order that is paid and handed over in one step — it reaches stock, commission and reports the same way."
            />

            {openSession ? (
                <div className="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <strong>Your till is open</strong> — {openSession.reference}
                        {openSession.register ? ` on ${openSession.register}` : ''}, taken so far{' '}
                        <MoneyText amount={openSession.sales_total} currency={currency} />
                    </div>
                    <Link href={`/pos/${openSession.id}`} className="btn btn-sm btn-success">Back to the counter</Link>
                </div>
            ) : can.sell ? (
                <div className="card mb-3">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Open a till</h2></div>
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/pos/open')
                            }}
                        >
                            <div className="col-12 col-md-5">
                                <label className="form-label" htmlFor="pos_register_id">Register</label>
                                <select
                                    id="pos_register_id"
                                    className={`form-select ${form.errors.pos_register_id ? 'is-invalid' : ''}`}
                                    value={form.data.pos_register_id}
                                    onChange={(e) => form.setData('pos_register_id', e.target.value)}
                                >
                                    <option value="">Choose a register…</option>
                                    {registers.map((register) => (
                                        <option key={register.id} value={register.id} disabled={register.busy}>
                                            {register.name}{register.busy ? ' — already open' : ''}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.pos_register_id ? <div className="invalid-feedback d-block">{form.errors.pos_register_id}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="opening_float">Opening float</label>
                                <input
                                    id="opening_float"
                                    className="form-control text-end font-monospace"
                                    inputMode="decimal"
                                    value={form.data.opening_float}
                                    onChange={(e) => form.setData('opening_float', e.target.value)}
                                />
                            </div>
                            <div className="col-6 col-md-3 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                                    {form.processing ? 'Opening…' : 'Open till'}
                                </button>
                            </div>
                        </form>
                        <p className="form-text mb-0">
                            Count the drawer before you open. What you type here is what the closing variance is measured against.
                        </p>
                    </div>
                </div>
            ) : null}

            <div className="row g-3">
                <div className="col-12 col-lg-5">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Registers</h2></div>
                        <div className="card-body p-0">
                            <ul className="list-group list-group-flush">
                                {registers.map((register) => (
                                    <li key={register.id} className="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <div className="fw-semibold">{register.name}</div>
                                            <div className="small text-body-secondary">
                                                {register.branch ?? 'No branch'} · sells from {register.warehouse ?? 'nowhere'}
                                            </div>
                                        </div>
                                        <StatusBadge label={register.busy ? 'in use' : 'free'} tone={register.busy ? 'warning' : 'success'} />
                                    </li>
                                ))}
                                {registers.length === 0 ? (
                                    <li className="list-group-item text-body-secondary small">
                                        No registers yet. One is needed before anything can be sold at a counter.
                                    </li>
                                ) : null}
                            </ul>
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-7">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Recent sessions</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} rows={recent} rowKey={(row) => row.id} emptyTitle="No till sessions yet" />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
