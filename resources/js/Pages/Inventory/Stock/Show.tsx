import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import DataTable, { type Column } from '@/Components/DataTable'

interface Movement {
    id: string
    reason: string
    quantity: string
    balance_after: string
    note: string | null
    actor: string
    at: string | null
}

interface Props {
    stock: {
        id: string
        sku: string | null
        product: string | null
        variant: string | null
        warehouse: string | null
        on_hand: string
        reserved: string
        available: string
        low_stock_threshold: string | null
    }
    movements: Movement[]
    reasons: { value: string; label: string }[]
    can: { adjust: boolean }
}

const qty = (value: string) => Number(value).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function StockShow({ stock, movements, reasons, can }: Props) {
    const adjust = useForm({ delta: '', reason: reasons[0]?.value ?? 'adjustment', note: '' })

    const columns: Column<Movement>[] = [
        { key: 'at', header: 'When', render: (row) => row.at ?? '—' },
        { key: 'reason', header: 'Reason', render: (row) => row.reason.replace('_', ' ') },
        {
            key: 'quantity',
            header: 'Change',
            align: 'end',
            render: (row) => (
                <span className={`font-monospace ${Number(row.quantity) < 0 ? 'text-danger' : 'text-success'}`}>
                    {Number(row.quantity) > 0 ? '+' : ''}{qty(row.quantity)}
                </span>
            ),
        },
        { key: 'balance', header: 'Balance after', align: 'end', render: (row) => <span className="font-monospace">{qty(row.balance_after)}</span> },
        { key: 'note', header: 'Note', render: (row) => row.note ?? '—' },
        { key: 'actor', header: 'By', render: (row) => row.actor },
    ]

    return (
        <AppLayout>
            <Head title={stock.sku ?? 'Stock line'} />

            <PageHeader
                title={`${stock.product ?? 'Unknown product'}${stock.variant ? ` — ${stock.variant}` : ''}`}
                subtitle={`${stock.sku ?? '—'} · ${stock.warehouse ?? 'No warehouse'}`}
                actions={<Link href="/inventory" className="btn btn-sm btn-outline-secondary">Back</Link>}
            />

            <div className="row g-3 mb-4">
                <div className="col-6 col-lg-3"><StatCard label="On hand" value={qty(stock.on_hand)} /></div>
                <div className="col-6 col-lg-3"><StatCard label="Reserved" value={qty(stock.reserved)} /></div>
                <div className="col-6 col-lg-3">
                    <StatCard label="Available" value={qty(stock.available)} tone={Number(stock.available) <= 0 ? 'danger' : 'success'} />
                </div>
                <div className="col-6 col-lg-3">
                    <StatCard label="Low stock at" value={stock.low_stock_threshold === null ? '—' : qty(stock.low_stock_threshold)} />
                </div>
            </div>

            <div className="row g-3">
                {can.adjust ? (
                    <div className="col-12 col-lg-4">
                        <div className="card h-100">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Adjust</h2></div>
                            <div className="card-body">
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault()
                                        adjust.post(`/inventory/${stock.id}/adjust`, { preserveScroll: true, onSuccess: () => adjust.reset('delta', 'note') })
                                    }}
                                >
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="delta">Change</label>
                                        <input
                                            id="delta"
                                            className={`form-control text-end font-monospace ${adjust.errors.delta ? 'is-invalid' : ''}`}
                                            inputMode="decimal"
                                            placeholder="-3 or 12"
                                            value={adjust.data.delta}
                                            onChange={(e) => adjust.setData('delta', e.target.value)}
                                        />
                                        {adjust.errors.delta ? <div className="invalid-feedback d-block">{adjust.errors.delta}</div> : null}
                                        <div className="form-text">Negative removes stock, positive adds it.</div>
                                    </div>

                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="reason">Reason</label>
                                        <select id="reason" className="form-select" value={adjust.data.reason} onChange={(e) => adjust.setData('reason', e.target.value)}>
                                            {reasons.map((reason) => (
                                                <option key={reason.value} value={reason.value}>{reason.label}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="note">Note</label>
                                        <textarea
                                            id="note"
                                            className={`form-control ${adjust.errors.note ? 'is-invalid' : ''}`}
                                            rows={2}
                                            value={adjust.data.note}
                                            onChange={(e) => adjust.setData('note', e.target.value)}
                                        />
                                        {adjust.errors.note ? <div className="invalid-feedback d-block">{adjust.errors.note}</div> : null}
                                        <div className="form-text">Required. Someone will read this in a year and need to know why.</div>
                                    </div>

                                    <button type="submit" className="btn btn-primary w-100" disabled={adjust.processing}>
                                        {adjust.processing ? 'Applying…' : 'Apply adjustment'}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                ) : null}

                <div className={can.adjust ? 'col-12 col-lg-8' : 'col-12'}>
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Movements</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} rows={movements} rowKey={(row) => row.id} emptyTitle="No movements yet" />
                        </div>
                        <div className="card-footer bg-body small text-body-secondary">
                            Movements are append-only and carry the balance after each change, so any figure here can be traced back.
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
