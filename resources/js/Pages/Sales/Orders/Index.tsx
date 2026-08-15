import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import Pagination from '@/Components/Pagination'
import SearchBar from '@/Components/SearchBar'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

interface Row {
    id: string
    order_number: string
    customer_name: string
    placed_at: string | null
    currency: string
    total: string
    outstanding: string
    payment_label: string
    payment_tone: Tone
    fulfilment_label: string
    fulfilment_tone: Tone
    exception: string
    exception_label: string
    owner: string | null
}

interface Option {
    value: string
    label: string
}

interface Props {
    orders: Paginated<Row>
    filters: { q: string; fulfilment: string; payment: string }
    statusOptions: { fulfilment: Option[]; payment: Option[] }
}

export default function OrderIndex({ orders, filters, statusOptions }: Props) {
    const { can } = useAuth()

    const applyFilter = (key: 'fulfilment' | 'payment', value: string) => {
        const next: Record<string, string> = { ...filters, [key]: value }
        Object.keys(next).forEach((k) => {
            if (next[k] === '') {
                delete next[k]
            }
        })
        router.get('/orders', next, { preserveState: true, replace: true })
    }

    const columns: Column<Row>[] = [
        {
            key: 'order',
            header: 'Order',
            render: (row) => (
                <div>
                    <Link href={`/orders/${row.id}`} className="fw-semibold text-decoration-none font-monospace">{row.order_number}</Link>
                    <div className="small text-body-secondary">{row.placed_at ?? 'Not placed'}</div>
                </div>
            ),
        },
        {
            key: 'customer',
            header: 'Customer',
            render: (row) => (
                <div>
                    <div>{row.customer_name}</div>
                    {row.owner ? <div className="small text-body-secondary">{row.owner}</div> : null}
                </div>
            ),
        },
        { key: 'total', header: 'Total', align: 'end', render: (row) => <MoneyText amount={row.total} currency={row.currency} /> },
        {
            key: 'outstanding',
            header: 'Outstanding',
            align: 'end',
            render: (row) =>
                Number(row.outstanding) === 0
                    ? <span className="text-body-secondary">settled</span>
                    : <MoneyText amount={row.outstanding} currency={row.currency} />,
        },
        { key: 'payment', header: 'Payment', render: (row) => <StatusBadge label={row.payment_label} tone={row.payment_tone} /> },
        {
            key: 'fulfilment',
            header: 'Fulfilment',
            render: (row) => (
                <>
                    <StatusBadge label={row.fulfilment_label} tone={row.fulfilment_tone} />
                    {row.exception !== 'none' ? (
                        <span className="ms-1"><StatusBadge label={row.exception_label} tone="danger" /></span>
                    ) : null}
                </>
            ),
        },
    ]

    return (
        <AppLayout>
            <Head title="Orders" />

            <PageHeader
                title="Orders"
                subtitle="Payment, fulfilment and exception move on separate tracks — an order can be paid and still unshipped."
                actions={can('orders.create') ? <Link href="/orders/create" className="btn btn-sm btn-primary">New order</Link> : null}
            />

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center gap-2 justify-content-between">
                    <SearchBar action="/orders" initial={filters.q} placeholder="Order number, customer, phone…" />
                    <div className="d-flex flex-wrap gap-2">
                        <select
                            className="form-select form-select-sm"
                            style={{ maxWidth: '11rem' }}
                            value={filters.fulfilment}
                            aria-label="Filter by fulfilment status"
                            onChange={(e) => applyFilter('fulfilment', e.target.value)}
                        >
                            <option value="">Any fulfilment</option>
                            {statusOptions.fulfilment.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                        <select
                            className="form-select form-select-sm"
                            style={{ maxWidth: '11rem' }}
                            value={filters.payment}
                            aria-label="Filter by payment status"
                            onChange={(e) => applyFilter('payment', e.target.value)}
                        >
                            <option value="">Any payment</option>
                            {statusOptions.payment.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </div>
                </div>
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={orders.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No orders match"
                        emptyDescription="Nothing here yet, or nothing within your data scope."
                    />
                </div>
                <div className="card-footer bg-body">
                    <Pagination meta={orders} />
                </div>
            </div>
        </AppLayout>
    )
}
