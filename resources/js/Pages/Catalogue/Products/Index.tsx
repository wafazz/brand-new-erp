import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import ExportButton from '@/Components/ExportButton'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    sku: string
    name: string
    type: string
    status: string
    category: string | null
    brand: string | null
    variants_count: number
    is_stock_tracked: boolean
}

interface Props {
    products: Paginated<Row>
    filters: { q: string; status: string }
}

const statusTone = (status: string) =>
    status === 'active' ? 'success' : status === 'discontinued' ? 'danger' : 'neutral'

export default function ProductIndex({ products, filters }: Props) {
    const { can } = useAuth()

    const columns: Column<Row>[] = [
        {
            key: 'name',
            header: 'Product',
            render: (row) => (
                <div>
                    <Link href={`/products/${row.id}`} className="fw-semibold text-decoration-none">{row.name}</Link>
                    <div className="small text-body-secondary font-monospace">{row.sku}</div>
                </div>
            ),
        },
        { key: 'category', header: 'Category', render: (row) => row.category ?? '—' },
        { key: 'brand', header: 'Brand', render: (row) => row.brand ?? '—' },
        { key: 'type', header: 'Type', render: (row) => row.type },
        { key: 'variants', header: 'Variants', align: 'end', render: (row) => String(row.variants_count) },
        {
            key: 'tracked',
            header: 'Stock',
            render: (row) => (row.is_stock_tracked ? <StatusBadge label="tracked" tone="info" /> : <span className="text-body-secondary">not tracked</span>),
        },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status} tone={statusTone(row.status)} /> },
    ]

    const filterByStatus = (status: string) => {
        router.get('/products', status === '' ? {} : { status }, { preserveState: true, replace: true })
    }

    return (
        <AppLayout>
            <Head title="Products" />

            <PageHeader
                title="Products"
                subtitle="Everything you sell, and the variants stock is counted against."
                actions={
                    <>
                        <ExportButton exportKey="products" ability="products.export" />
                        {can('products.create') ? <Link href="/products/create" className="btn btn-sm btn-primary">New product</Link> : null}
                    </>
                }
            />

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <SearchBar action="/products" initial={filters.q} placeholder="Name or SKU…" />
                    <div className="btn-group btn-group-sm" role="group" aria-label="Filter by status">
                        {['', 'active', 'inactive', 'discontinued'].map((status) => (
                            <button
                                key={status || 'all'}
                                type="button"
                                className={`btn btn-outline-secondary ${filters.status === status ? 'active' : ''}`}
                                onClick={() => filterByStatus(status)}
                            >
                                {status === '' ? 'All' : status}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={products.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No products yet"
                        emptyDescription="Add your first product to start quoting and selling."
                    />
                </div>
                <div className="card-footer bg-body">
                    <Pagination meta={products} />
                </div>
            </div>
        </AppLayout>
    )
}
