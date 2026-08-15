import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import StatCard from '@/Components/StatCard'
import Pagination from '@/Components/Pagination'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

interface Row {
    id: string
    recipient: string
    role: string | null
    order_id: string | null
    order_number: string | null
    type: string
    status: string
    is_provisional: boolean
    currency: string
    basis_amount: string
    rate_applied: string
    rate_type: string
    amount: string
}

interface Props {
    commissions: Paginated<Row>
    filters: { period: string; status: string }
    totals: Record<string, string>
    can: { approve: boolean; pay: boolean }
}

const tone = (status: string) =>
    status === 'paid' ? 'success' : status === 'reversed' ? 'danger' : status === 'payable' ? 'warning' : status === 'approved' ? 'info' : 'neutral'

export default function CommissionIndex({ commissions, filters, totals }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const columns: Column<Row>[] = [
        {
            key: 'recipient',
            header: 'Recipient',
            render: (row) => (
                <div>
                    <Link href={`/commissions/${row.id}`} className="fw-semibold text-decoration-none">{row.recipient}</Link>
                    {row.role ? <div className="small text-body-secondary">{row.role}</div> : null}
                </div>
            ),
        },
        {
            key: 'order',
            header: 'Order',
            render: (row) =>
                row.order_id
                    ? <Link href={`/orders/${row.order_id}`} className="font-monospace small">{row.order_number}</Link>
                    : <span className="text-body-secondary">—</span>,
        },
        { key: 'type', header: 'Type', render: (row) => row.type },
        { key: 'basis', header: 'Basis', align: 'end', render: (row) => <MoneyText amount={row.basis_amount} currency={row.currency} muted /> },
        {
            key: 'rate',
            header: 'Rate',
            align: 'end',
            render: (row) => (
                <span className="font-monospace small">
                    {row.rate_type === 'percent' ? `${Number(row.rate_applied).toFixed(2)}%` : Number(row.rate_applied).toFixed(2)}
                </span>
            ),
        },
        { key: 'amount', header: 'Amount', align: 'end', render: (row) => <MoneyText amount={row.amount} currency={row.currency} /> },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <>
                    <StatusBadge label={row.status} tone={tone(row.status)} />
                    {row.is_provisional ? <span className="ms-1 small text-body-secondary">provisional</span> : null}
                </>
            ),
        },
    ]

    return (
        <AppLayout>
            <Head title="Commission" />

            <PageHeader
                title="Commission"
                subtitle="Every figure here can explain itself — open one to see the rule, the basis and the arithmetic."
            />

            <div className="row g-3 mb-4">
                {Object.entries(totals).map(([bucket, amount]) => (
                    <div key={bucket} className="col-6 col-lg">
                        <StatCard
                            label={bucket}
                            value={`${currency} ${Number(amount).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                            tone={bucket === 'paid' ? 'success' : bucket === 'reversed' ? 'danger' : 'default'}
                        />
                    </div>
                ))}
            </div>

            <div className="card">
                <div className="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div className="d-flex gap-2 align-items-center">
                        <label className="form-label mb-0 small" htmlFor="period">Period</label>
                        <input
                            id="period"
                            type="month"
                            className="form-control form-control-sm"
                            style={{ maxWidth: '10rem' }}
                            value={filters.period}
                            onChange={(e) => router.get('/commissions', { period: e.target.value }, { preserveState: true, replace: true })}
                        />
                    </div>
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '12rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/commissions', e.target.value === '' ? { period: filters.period } : { period: filters.period, status: e.target.value }, { preserveState: true, replace: true })}
                    >
                        <option value="">Any status</option>
                        {['pending', 'approved', 'payable', 'paid', 'reversed'].map((status) => (
                            <option key={status} value={status}>{status}</option>
                        ))}
                    </select>
                </div>
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={commissions.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No commission in this period"
                        emptyDescription="Commission accrues from orders, so it appears once orders in this period reach a payable state."
                    />
                </div>
                <div className="card-footer bg-body"><Pagination meta={commissions} /></div>
            </div>
        </AppLayout>
    )
}
