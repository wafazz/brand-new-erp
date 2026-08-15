import DataTable, { type Column } from './DataTable'
import MoneyText from './MoneyText'
import type { Slice } from '@/Types/dashboard'

interface Props {
    title: string
    rows: Slice[]
    currency: string
}

export default function SliceTable({ title, rows, currency }: Props) {
    const columns: Column<Slice>[] = [
        { key: 'slice', header: 'Reference', render: (row) => <span className="font-monospace small">{row.slice}</span> },
        { key: 'orders', header: 'Orders', align: 'end', render: (row) => row.orders },
        { key: 'revenue', header: 'Revenue', align: 'end', render: (row) => <MoneyText amount={row.revenue} currency={currency} /> },
    ]

    return (
        <div className="card h-100">
            <div className="card-header bg-body">
                <h2 className="h6 mb-0">{title}</h2>
            </div>
            <div className="card-body">
                <DataTable columns={columns} rows={rows} rowKey={(row) => row.slice} emptyTitle="Nothing in scope" />
            </div>
        </div>
    )
}
