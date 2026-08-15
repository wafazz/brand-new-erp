import type { ReactNode } from 'react'
import EmptyState from './EmptyState'

export interface Column<T> {
    key: string
    header: string
    align?: 'start' | 'center' | 'end' | undefined
    render: (row: T) => ReactNode
}

interface Props<T> {
    columns: Column<T>[]
    rows: T[]
    rowKey: (row: T) => string
    emptyTitle?: string | undefined
    emptyDescription?: string | undefined
}

export default function DataTable<T>({ columns, rows, rowKey, emptyTitle, emptyDescription }: Props<T>) {
    if (rows.length === 0) {
        return <EmptyState title={emptyTitle ?? 'Nothing here yet'} description={emptyDescription} />
    }

    return (
        <div className="table-responsive">
            <table className="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column.key} scope="col" className={`text-${column.align ?? 'start'}`}>
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={rowKey(row)}>
                            {columns.map((column) => (
                                <td key={column.key} className={`text-${column.align ?? 'start'}`}>
                                    {column.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}
