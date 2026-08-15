import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import { useAuth } from '@/Hooks/useAuth'

interface Variant {
    id: string
    sku: string
    name: string
    barcode: string | null
    cost_price: string
    average_cost: string
    selling_price: string
    is_default: boolean
    is_active: boolean
    on_hand: string
    reserved: string
}

interface Props {
    product: {
        id: string
        sku: string
        name: string
        type: string
        status: string
        description: string | null
        category: string | null
        brand: string | null
        unit: string | null
        tax_rate: string | null
        is_stock_tracked: boolean
        has_variants: boolean
    }
    variants: Variant[]
}

export default function ProductShow({ product, variants }: Props) {
    const { can, company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const columns: Column<Variant>[] = [
        {
            key: 'variant',
            header: 'Variant',
            render: (row) => (
                <div>
                    <span className="fw-semibold">{row.name}</span>
                    {row.is_default ? <span className="ms-2"><StatusBadge label="default" tone="info" /></span> : null}
                    {row.is_active ? null : <span className="ms-2"><StatusBadge label="inactive" tone="neutral" /></span>}
                    <div className="small text-body-secondary font-monospace">{row.sku}</div>
                </div>
            ),
        },
        { key: 'cost', header: 'Cost', align: 'end', render: (row) => <MoneyText amount={row.cost_price} currency={currency} muted /> },
        {
            key: 'average',
            header: 'Average cost',
            align: 'end',
            render: (row) => <MoneyText amount={row.average_cost} currency={currency} muted />,
        },
        { key: 'price', header: 'Price', align: 'end', render: (row) => <MoneyText amount={row.selling_price} currency={currency} /> },
        {
            key: 'on_hand',
            header: 'On hand',
            align: 'end',
            render: (row) => (product.is_stock_tracked ? <span className="font-monospace">{Number(row.on_hand).toFixed(2)}</span> : <span className="text-body-secondary">—</span>),
        },
        {
            key: 'reserved',
            header: 'Reserved',
            align: 'end',
            render: (row) => (product.is_stock_tracked ? <span className="font-monospace text-body-secondary">{Number(row.reserved).toFixed(2)}</span> : <span className="text-body-secondary">—</span>),
        },
    ]

    return (
        <AppLayout>
            <Head title={product.name} />

            <PageHeader
                title={product.name}
                subtitle={`${product.sku} · ${product.type}`}
                actions={
                    <>
                        <Link href="/products" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {can('products.update') ? <Link href={`/products/${product.id}/edit`} className="btn btn-sm btn-primary">Edit</Link> : null}
                        {can('products.delete') ? (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => router.delete(`/products/${product.id}`)}>
                                Remove
                            </button>
                        ) : null}
                    </>
                }
            />

            <div className="row g-3">
                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Details</h2></div>
                        <div className="card-body">
                            <dl className="row mb-0 small">
                                <dt className="col-5">Status</dt>
                                <dd className="col-7"><StatusBadge label={product.status} tone={product.status === 'active' ? 'success' : 'neutral'} /></dd>
                                <dt className="col-5">Category</dt>
                                <dd className="col-7">{product.category ?? '—'}</dd>
                                <dt className="col-5">Brand</dt>
                                <dd className="col-7">{product.brand ?? '—'}</dd>
                                <dt className="col-5">Unit</dt>
                                <dd className="col-7">{product.unit ?? 'Each'}</dd>
                                <dt className="col-5">Tax</dt>
                                <dd className="col-7">{product.tax_rate ?? 'None'}</dd>
                                <dt className="col-5">Stock</dt>
                                <dd className="col-7">{product.is_stock_tracked ? 'Tracked' : 'Not tracked'}</dd>
                            </dl>
                            {product.description ? <p className="small text-body-secondary mt-3 mb-0">{product.description}</p> : null}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-8">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Variants</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} rows={variants} rowKey={(row) => row.id} emptyTitle="No variants" />
                        </div>
                        <div className="card-footer bg-body small text-body-secondary">
                            Average cost is recomputed from the full receipt history, including apportioned landed cost.
                            It is not the price you typed on the purchase order.
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
