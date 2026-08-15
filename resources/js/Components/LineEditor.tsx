import type { ReactNode } from 'react'

interface Props {
    title: string
    onAdd: () => void
    addLabel?: string | undefined
    error?: string | undefined
    footer?: ReactNode | undefined
    children: ReactNode
}

export default function LineEditor({ title, onAdd, addLabel = 'Add line', error, footer, children }: Props) {
    return (
        <div className="card">
            <div className="card-header bg-body d-flex align-items-center justify-content-between">
                <h2 className="h6 mb-0">{title}</h2>
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={onAdd}>{addLabel}</button>
            </div>
            <div className="card-body">
                {error ? <div className="alert alert-danger py-2">{error}</div> : null}
                {children}
            </div>
            {footer ? <div className="card-footer bg-body">{footer}</div> : null}
        </div>
    )
}
