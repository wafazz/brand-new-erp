import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import ExportButton from '@/Components/ExportButton'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    sku: string | null
    product: string | null
    variant: string | null
    warehouse: string | null
    on_hand: string
    reserved: string
    available: string
    low_stock_threshold: string | null
    is_low: boolean
}

interface Props {
    lines: Paginated<Row>
    filters: { q: string; warehouse: string; low: boolean }
    warehouses: { value: string; label: string }[]
    can: { adjust: boolean }
}

const qty = (value: string) => Number(value).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function StockIndex({ lines, filters, warehouses, can }: Props) {
    const apply = (patch: Record<string, string>) => {
        const next: Record<string, string> = {
            ...(filters.q === '' ? {} : { q: filters.q }),
            ...(filters.warehouse === '' ? {} : { warehouse: filters.warehouse }),
            ...(filters.low ? { low: '1' } : {}),
            ...patch,
        }

        Object.keys(next).forEach((key) => {
            if (next[key] === '') {
                delete next[key]
            }
        })

        router.get('/inventory', next, { preserveState: true, replace: true })
    }

    const columns: Column<Row>[] = [
        {
            key: 'item',
            header: 'Item',
            render: (row) => (
                <div>
                    <Link href={`/inventory/${row.id}`} className="fw-semibold text-decoration-none">
                        {row.product ?? 'Unknown product'}{row.variant ? ` — ${row.variant}` : ''}
                    </Link>
                    <div className="small text-body-secondary font-monospace">{row.sku ?? '—'}</div>
                </div>
            ),
        },
        { key: 'warehouse', header: 'Warehouse', render: (row) => row.warehouse ?? '—' },
        { key: 'on_hand', header: 'On hand', align: 'end', render: (row) => <span className="font-monospace">{qty(row.on_hand)}</span> },
        { key: 'reserved', header: 'Reserved', align: 'end', render: (row) => <span className="font-monospace text-body-secondary">{qty(row.reserved)}</span> },
        {
            key: 'available',
            header: 'Available',
            align: 'end',
            render: (row) => (
                <span className={`font-monospace ${Number(row.available) <= 0 ? 'text-danger' : ''}`}>{qty(row.available)}</span>
            ),
        },
        {
            key: 'low',
            header: 'Level',
            render: (row) =>
                row.low_stock_threshold === null
                    ? <span className="text-body-secondary small">no threshold</span>
                    : row.is_low
                        ? <StatusBadge label="low" tone="warning" />
                        : <StatusBadge label="ok" tone="success" />,
        },
    ]

    return (
        <AppLayout>
            <Head title="Inventory" />

            <PageHeader
                title="Inventory"
                subtitle="On hand minus reserved is what you can actually sell."
                actions={
                    <>
                        <ExportButton exportKey="inventory" ability="inventory.export" />
                        {can.adjust ? <Link href="/inventory/create" className="btn btn-sm btn-primary">Open a stock line</Link> : null}
                    </>
                }
            />

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <SearchBar action="/inventory" initial={filters.q} placeholder="SKU or variant…" />
                    <div className="d-flex flex-wrap gap-2 align-items-center">
                        <select
                            className="form-select form-select-sm"
                            style={{ maxWidth: '12rem' }}
                            value={filters.warehouse}
                            aria-label="Filter by warehouse"
                            onChange={(e) => apply({ warehouse: e.target.value })}
                        >
                            <option value="">All warehouses</option>
                            {warehouses.map((warehouse) => (
                                <option key={warehouse.value} value={warehouse.value}>{warehouse.label}</option>
                            ))}
                        </select>
                        <div className="form-check mb-0">
                            <input
                                id="low"
                                className="form-check-input"
                                type="checkbox"
                                checked={filters.low}
                                onChange={(e) => apply({ low: e.target.checked ? '1' : '' })}
                            />
                            <label className="form-check-label small" htmlFor="low">Low stock only</label>
                        </div>
                    </div>
                </div>
                <div className="card-body p-0">
                    <DataTable columns={columns} rows={lines.data} rowKey={(row) => row.id} emptyTitle="No stock lines" />
                </div>
                <div className="card-footer bg-body"><Pagination meta={lines} /></div>
            </div>
        </AppLayout>
    )
}
