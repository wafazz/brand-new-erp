import { Head, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import MoneyText from '@/Components/MoneyText'
import { useAuth } from '@/Hooks/useAuth'

type Row = Record<string, string | null | undefined>

interface Props {
    filters: { from: string; to: string }
    campaigns: Row[]
    marketers: Row[]
    salespeople: Row[]
    channels: Row[]
    spendVersusReturn: Row[]
    costPerLead: Row[]
    branches: Row[]
}

const num = (value: string | null | undefined) => Number(value ?? 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function AttributionIndex({ filters, campaigns, marketers, salespeople, channels, spendVersusReturn, costPerLead, branches }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const move = (patch: Partial<typeof filters>) => {
        router.get('/attribution', { ...filters, ...patch }, { preserveState: true, replace: true })
    }

    const revenueColumns = (label: string): Column<Row>[] => [
        {
            key: 'name',
            header: label,
            render: (row) => (
                <div>
                    <span>{row.name ?? 'Unattributed'}</span>
                    {row.code ? <div className="small text-body-secondary font-monospace">{row.code}</div> : null}
                </div>
            ),
        },
        { key: 'orders', header: 'Orders', align: 'end', render: (row) => row.orders ?? '0' },
        { key: 'revenue', header: 'Revenue', align: 'end', render: (row) => <MoneyText amount={row.revenue ?? '0'} currency={currency} /> },
    ]

    const channelColumns: Column<Row>[] = [
        { key: 'name', header: 'Channel', render: (row) => row.name ?? '—' },
        { key: 'leads', header: 'Leads', align: 'end', render: (row) => row.leads ?? '0' },
        { key: 'orders', header: 'Orders', align: 'end', render: (row) => row.orders ?? '0' },
        {
            key: 'conversion',
            header: 'Converts',
            align: 'end',
            render: (row) => {
                const leads = Number(row.leads ?? 0)
                const orders = Number(row.orders ?? 0)

                return leads === 0 ? <span className="text-body-secondary">—</span> : `${((orders / leads) * 100).toFixed(1)}%`
            },
        },
        { key: 'revenue', header: 'Revenue', align: 'end', render: (row) => <MoneyText amount={row.revenue ?? '0'} currency={currency} /> },
    ]

    const roasColumns: Column<Row>[] = [
        {
            key: 'name',
            header: 'Campaign',
            render: (row) => (
                <div>
                    <span>{row.name ?? '—'}</span>
                    {row.code ? <div className="small text-body-secondary font-monospace">{row.code}</div> : null}
                </div>
            ),
        },
        { key: 'spend', header: 'Spend', align: 'end', render: (row) => <MoneyText amount={row.spend ?? '0'} currency={currency} muted /> },
        { key: 'revenue', header: 'Revenue', align: 'end', render: (row) => <MoneyText amount={row.revenue ?? '0'} currency={currency} /> },
        {
            key: 'net',
            header: 'Net',
            align: 'end',
            render: (row) => (
                <span className={Number(row.net ?? 0) < 0 ? 'text-danger fw-semibold' : ''}>
                    <MoneyText amount={row.net ?? '0'} currency={currency} />
                </span>
            ),
        },
        {
            key: 'roas',
            header: 'Return on spend',
            align: 'end',
            render: (row) => (row.roas == null ? <span className="text-body-secondary">no spend</span> : `${num(row.roas)}×`),
        },
    ]

    const cplColumns: Column<Row>[] = [
        { key: 'name', header: 'Campaign', render: (row) => row.name ?? '—' },
        { key: 'spend', header: 'Spend', align: 'end', render: (row) => <MoneyText amount={row.spend ?? '0'} currency={currency} muted /> },
        { key: 'leads', header: 'Leads', align: 'end', render: (row) => row.leads ?? '0' },
        {
            key: 'cost_per_lead',
            header: 'Cost per lead',
            align: 'end',
            render: (row) => (row.cost_per_lead == null ? <span className="text-body-secondary">—</span> : <MoneyText amount={row.cost_per_lead} currency={currency} />),
        },
    ]

    const panels: { title: string; note: string; columns: Column<Row>[]; rows: Row[] }[] = [
        { title: 'Which campaign generated revenue', note: 'Attribution frozen on the order, not recomputed.', columns: revenueColumns('Campaign'), rows: campaigns },
        { title: 'Which channel converts best', note: 'Leads counted from first touch; orders from frozen attribution.', columns: channelColumns, rows: channels },
        { title: 'What each campaign cost against what it returned', note: 'Cancelled orders are excluded from revenue.', columns: roasColumns, rows: spendVersusReturn },
        { title: 'Cost per lead by campaign', note: 'Spend over the whole life of the campaign, not the window.', columns: cplColumns, rows: costPerLead },
        { title: 'Which marketer generated revenue', note: 'Credited from the marketer frozen on the order.', columns: revenueColumns('Marketer'), rows: marketers },
        { title: 'Which salesperson generated revenue', note: 'The closer, not the lead source.', columns: revenueColumns('Salesperson'), rows: salespeople },
        { title: 'Which branch generated what', note: '', columns: revenueColumns('Branch'), rows: branches },
    ]

    return (
        <AppLayout>
            <Head title="Attribution" />

            <PageHeader
                title="Attribution"
                subtitle="Where the business came from, answered from what was frozen onto each order when it was placed."
            />

            <div className="card mb-3">
                <div className="card-body d-flex flex-wrap gap-3 align-items-end">
                    <div>
                        <label className="form-label small" htmlFor="from">From</label>
                        <input id="from" type="date" className="form-control form-control-sm" value={filters.from} onChange={(e) => move({ from: e.target.value })} />
                    </div>
                    <div>
                        <label className="form-label small" htmlFor="to">To</label>
                        <input id="to" type="date" className="form-control form-control-sm" value={filters.to} onChange={(e) => move({ to: e.target.value })} />
                    </div>
                    <p className="form-text mb-0 ms-auto" style={{ maxWidth: '28rem' }}>
                        Attribution never moves after an order is placed, so a report run today for last month gives the
                        same answer it gave then.
                    </p>
                </div>
            </div>

            <div className="row g-3">
                {panels.map((panel) => (
                    <div key={panel.title} className="col-12 col-xl-6">
                        <div className="card h-100">
                            <div className="card-header bg-body">
                                <h2 className="h6 mb-0">{panel.title}</h2>
                                {panel.note ? <div className="small text-body-secondary">{panel.note}</div> : null}
                            </div>
                            <div className="card-body p-0">
                                <DataTable
                                    columns={panel.columns}
                                    rows={panel.rows}
                                    rowKey={(row) => String(row.code ?? row.name ?? Math.random())}
                                    emptyTitle="Nothing in this window"
                                />
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    )
}
