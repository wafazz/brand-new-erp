import { Head, Link, useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import { useAuth } from '@/Hooks/useAuth'

interface Variant {
    id: string
    sku: string
    name: string
    price: string
    on_hand: string
}

interface Line {
    variant_id: string
    quantity: string
}

interface Sale {
    id: string
    order_number: string
    total: string
    currency: string
    placed_at: string | null
}

interface Movement {
    id: string
    kind: string
    amount: string
    reason: string
    at: string | null
}

interface Props {
    session: {
        id: string
        reference: string
        register: string | null
        cashier: string | null
        status: string
        opening_float: string
        expected_cash: string
        counted_cash: string | null
        variance: string | null
        sales_count: string
        sales_total: string
        opened_at: string | null
        closed_at: string | null
    }
    variants: Variant[]
    customers: { value: string; label: string }[]
    sales: Sale[]
    movements: Movement[]
    can: { sell: boolean; manage: boolean }
}

const money = (v: string | number) => Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function PosSessionScreen({ session, variants, customers, sales, movements, can }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const [search, setSearch] = useState('')
    const [lines, setLines] = useState<Line[]>([])
    const [cashOpen, setCashOpen] = useState(false)
    const [closeOpen, setCloseOpen] = useState(false)

    const sale = useForm<{ customer_id: string; lines: Line[]; tenders: { method: string; amount: string; reference: string }[] }>({
        customer_id: '',
        lines: [],
        tenders: [{ method: 'cash', amount: '', reference: '' }],
    })
    const cash = useForm({ kind: 'cash_out', amount: '', reason: '' })
    const closing = useForm({ counted_cash: '', closing_note: '' })

    const open = session.status === 'open'

    const priceOf = (id: string) => variants.find((v) => v.id === id)?.price ?? '0'

    const total = useMemo(
        () => lines.reduce((sum, line) => sum + Number(line.quantity) * Number(priceOf(line.variant_id)), 0),
        [lines]
    )

    const tendered = sale.data.tenders.reduce((sum, t) => sum + (Number(t.amount) || 0), 0)
    const change = tendered - total

    const matches = useMemo(() => {
        const term = search.trim().toLowerCase()

        return term === ''
            ? variants.slice(0, 12)
            : variants.filter((v) => v.sku.toLowerCase().includes(term) || v.name.toLowerCase().includes(term)).slice(0, 12)
    }, [search, variants])

    const addLine = (variantId: string) => {
        setLines((current) => {
            const existing = current.find((l) => l.variant_id === variantId)

            return existing
                ? current.map((l) => (l.variant_id === variantId ? { ...l, quantity: String(Number(l.quantity) + 1) } : l))
                : [...current, { variant_id: variantId, quantity: '1' }]
        })
        setSearch('')
    }

    const submitSale = () => {
        sale.transform((data) => ({ ...data, lines, tenders: data.tenders.filter((t) => Number(t.amount) > 0) }))
        sale.post(`/pos/${session.id}/sell`, {
            preserveScroll: true,
            onSuccess: () => {
                setLines([])
                sale.setData('tenders', [{ method: 'cash', amount: '', reference: '' }])
                sale.setData('customer_id', '')
            },
        })
    }

    return (
        <AppLayout>
            <Head title={`Till ${session.reference}`} />

            <PageHeader
                title={session.register ?? 'Counter'}
                subtitle={`${session.reference} · ${session.cashier ?? ''}${session.opened_at ? ` · opened ${session.opened_at}` : ''}`}
                actions={
                    <>
                        <Link href="/pos" className="btn btn-sm btn-outline-secondary">All tills</Link>
                        {open && can.sell ? (
                            <>
                                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setCashOpen((o) => !o)}>Till movement</button>
                                <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setCloseOpen((o) => !o)}>Close till</button>
                            </>
                        ) : null}
                    </>
                }
            />

            <div className="row g-3 mb-3">
                <div className="col-6 col-lg-3"><StatCard label="Sales" value={session.sales_count} /></div>
                <div className="col-6 col-lg-3"><StatCard label="Taken" value={`${currency} ${money(session.sales_total)}`} /></div>
                <div className="col-6 col-lg-3"><StatCard label="Float" value={`${currency} ${money(session.opening_float)}`} /></div>
                <div className="col-6 col-lg-3">
                    <StatCard
                        label="Drawer should hold"
                        value={`${currency} ${money(session.expected_cash)}`}
                        hint="Float plus cash sales, plus or minus till movements"
                    />
                </div>
            </div>

            {!open ? (
                <div className="alert alert-secondary">
                    <div className="d-flex flex-wrap gap-3">
                        <StatusBadge label="closed" tone="neutral" />
                        <span>Counted <strong>{currency} {money(session.counted_cash ?? '0')}</strong></span>
                        <span>Expected <strong>{currency} {money(session.expected_cash)}</strong></span>
                        <span>
                            Variance{' '}
                            <strong className={Number(session.variance ?? 0) === 0 ? 'text-success' : 'text-danger'}>
                                {currency} {money(session.variance ?? '0')}
                            </strong>
                        </span>
                    </div>
                </div>
            ) : null}

            {cashOpen && open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                cash.post(`/pos/${session.id}/cash`, { preserveScroll: true, onSuccess: () => { cash.reset(); setCashOpen(false) } })
                            }}
                        >
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="kind">Movement</label>
                                <select id="kind" className="form-select" value={cash.data.kind} onChange={(e) => cash.setData('kind', e.target.value)}>
                                    <option value="cash_in">Cash in</option>
                                    <option value="cash_out">Cash out</option>
                                    <option value="drop">Drop to safe</option>
                                </select>
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="amount">Amount</label>
                                <input id="amount" className={`form-control text-end font-monospace ${cash.errors.amount ? 'is-invalid' : ''}`} inputMode="decimal" value={cash.data.amount} onChange={(e) => cash.setData('amount', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="reason">Reason</label>
                                <input id="reason" className={`form-control ${cash.errors.reason ? 'is-invalid' : ''}`} value={cash.data.reason} onChange={(e) => cash.setData('reason', e.target.value)} />
                                {cash.errors.reason ? <div className="invalid-feedback d-block">{cash.errors.reason}</div> : null}
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={cash.processing}>Record</button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            {closeOpen && open ? (
                <div className="card mb-3 border-danger">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                closing.post(`/pos/${session.id}/close`, { preserveScroll: true })
                            }}
                        >
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="counted_cash">Counted cash</label>
                                <input id="counted_cash" className={`form-control text-end font-monospace ${closing.errors.counted_cash ? 'is-invalid' : ''}`} inputMode="decimal" value={closing.data.counted_cash} onChange={(e) => closing.setData('counted_cash', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-6">
                                <label className="form-label" htmlFor="closing_note">Note</label>
                                <input id="closing_note" className="form-control" value={closing.data.closing_note} onChange={(e) => closing.setData('closing_note', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-3 d-grid">
                                <button type="submit" className="btn btn-danger" disabled={closing.processing}>Close till</button>
                            </div>
                            <div className="col-12">
                                <p className="form-text mb-0">
                                    Count the drawer first and type what is really there. The variance is worked out for you —
                                    it is never something you enter.
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            {open && can.sell ? (
                <div className="row g-3">
                    <div className="col-12 col-lg-7">
                        <div className="card">
                            <div className="card-header bg-body">
                                <input
                                    className="form-control"
                                    placeholder="Scan or type a SKU…"
                                    value={search}
                                    autoFocus
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && matches.length > 0) {
                                            e.preventDefault()
                                            addLine(matches[0]!.id)
                                        }
                                    }}
                                />
                            </div>
                            <div className="card-body p-0">
                                <div className="list-group list-group-flush">
                                    {matches.map((variant) => (
                                        <button
                                            key={variant.id}
                                            type="button"
                                            className="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                            onClick={() => addLine(variant.id)}
                                        >
                                            <span>
                                                <span className="fw-semibold">{variant.name}</span>
                                                <span className="small text-body-secondary d-block font-monospace">{variant.sku}</span>
                                            </span>
                                            <span className="text-end">
                                                <span className="font-monospace">{currency} {money(variant.price)}</span>
                                                <span className={`small d-block ${Number(variant.on_hand) <= 0 ? 'text-danger' : 'text-body-secondary'}`}>
                                                    {Number(variant.on_hand).toFixed(0)} on shelf
                                                </span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="col-12 col-lg-5">
                        <div className="card">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">This sale</h2></div>
                            <div className="card-body">
                                {lines.length === 0 ? (
                                    <p className="text-body-secondary small mb-0">Nothing scanned yet.</p>
                                ) : (
                                    <table className="table table-sm align-middle mb-3">
                                        <tbody>
                                            {lines.map((line, index) => {
                                                const variant = variants.find((v) => v.id === line.variant_id)

                                                return (
                                                    <tr key={line.variant_id}>
                                                        <td>
                                                            <div className="small">{variant?.name}</div>
                                                            <div className="small text-body-secondary font-monospace">{variant?.sku}</div>
                                                        </td>
                                                        <td style={{ width: '5rem' }}>
                                                            <input
                                                                className="form-control form-control-sm text-end font-monospace"
                                                                inputMode="decimal"
                                                                aria-label={`Quantity for ${variant?.sku ?? 'line'}`}
                                                                value={line.quantity}
                                                                onChange={(e) => setLines((c) => c.map((l, i) => (i === index ? { ...l, quantity: e.target.value } : l)))}
                                                            />
                                                        </td>
                                                        <td className="text-end font-monospace small">
                                                            {money(Number(line.quantity) * Number(priceOf(line.variant_id)))}
                                                        </td>
                                                        <td className="text-end">
                                                            <button
                                                                type="button"
                                                                className="btn btn-sm btn-outline-danger"
                                                                aria-label={`Remove ${variant?.sku ?? 'line'}`}
                                                                onClick={() => setLines((c) => c.filter((_, i) => i !== index))}
                                                            >
                                                                <i className="bi bi-x-lg" aria-hidden="true" />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                )
                                            })}
                                        </tbody>
                                    </table>
                                )}

                                <div className="d-flex justify-content-between align-items-center border-top pt-2 mb-3">
                                    <span className="h6 mb-0">Total</span>
                                    <span className="h5 mb-0 font-monospace">{currency} {money(total)}</span>
                                </div>

                                <label className="form-label small" htmlFor="customer_id">Customer</label>
                                <select id="customer_id" className="form-select form-select-sm mb-3" value={sale.data.customer_id} onChange={(e) => sale.setData('customer_id', e.target.value)}>
                                    <option value="">Walk-in</option>
                                    {customers.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                                </select>

                                {sale.data.tenders.map((tender, index) => (
                                    <div key={`tender-${index}`} className="row g-2 mb-2">
                                        <div className="col-5">
                                            <select
                                                className="form-select form-select-sm"
                                                aria-label={`Payment method ${index + 1}`}
                                                value={tender.method}
                                                onChange={(e) => sale.setData('tenders', sale.data.tenders.map((t, i) => (i === index ? { ...t, method: e.target.value } : t)))}
                                            >
                                                <option value="cash">Cash</option>
                                                <option value="card">Card</option>
                                                <option value="ewallet">E-wallet</option>
                                            </select>
                                        </div>
                                        <div className="col-7">
                                            <input
                                                className="form-control form-control-sm text-end font-monospace"
                                                inputMode="decimal"
                                                placeholder="0.00"
                                                aria-label={`Amount tendered ${index + 1}`}
                                                value={tender.amount}
                                                onChange={(e) => sale.setData('tenders', sale.data.tenders.map((t, i) => (i === index ? { ...t, amount: e.target.value } : t)))}
                                            />
                                        </div>
                                    </div>
                                ))}

                                <button
                                    type="button"
                                    className="btn btn-sm btn-link p-0 mb-3"
                                    onClick={() => sale.setData('tenders', [...sale.data.tenders, { method: 'card', amount: '', reference: '' }])}
                                >
                                    Split payment
                                </button>

                                <div className="d-flex justify-content-between small mb-3">
                                    <span className="text-body-secondary">Change</span>
                                    <span className={`font-monospace ${change < 0 ? 'text-danger' : ''}`}>
                                        {currency} {money(Math.max(change, 0))}
                                        {change < 0 ? ` (short ${money(-change)})` : ''}
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    className="btn btn-primary w-100"
                                    disabled={sale.processing || lines.length === 0 || change < 0}
                                    onClick={submitSale}
                                >
                                    {sale.processing ? 'Taking…' : 'Take payment'}
                                </button>
                                <p className="form-text mb-0">
                                    The server checks the tender again and refuses anything short, so a rounding slip here
                                    cannot let goods out unpaid.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}

            <div className="row g-3 mt-1">
                <div className="col-12 col-lg-7">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Sales this session</h2></div>
                        <div className="card-body p-0">
                            <ul className="list-group list-group-flush">
                                {sales.map((row) => (
                                    <li key={row.id} className="list-group-item d-flex justify-content-between">
                                        <Link href={`/orders/${row.id}`} className="font-monospace small text-decoration-none">{row.order_number}</Link>
                                        <span><span className="small text-body-secondary me-3">{row.placed_at}</span><MoneyText amount={row.total} currency={row.currency} /></span>
                                    </li>
                                ))}
                                {sales.length === 0 ? <li className="list-group-item small text-body-secondary">Nothing sold yet.</li> : null}
                            </ul>
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-5">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Till movements</h2></div>
                        <div className="card-body p-0">
                            <ul className="list-group list-group-flush">
                                {movements.map((row) => (
                                    <li key={row.id} className="list-group-item d-flex justify-content-between">
                                        <span>
                                            <span className="small">{row.reason}</span>
                                            <span className="small text-body-secondary d-block">{row.kind.replace('_', ' ')} · {row.at}</span>
                                        </span>
                                        <span className={`font-monospace ${row.kind === 'cash_in' ? 'text-success' : 'text-danger'}`}>
                                            {row.kind === 'cash_in' ? '+' : '−'}{money(row.amount)}
                                        </span>
                                    </li>
                                ))}
                                {movements.length === 0 ? <li className="list-group-item small text-body-secondary">Nothing in or out.</li> : null}
                            </ul>
                        </div>
                        <div className="card-footer bg-body small text-body-secondary">
                            Till movements are append-only. The database refuses to change one after it is written.
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
