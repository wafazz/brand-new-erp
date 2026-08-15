import type { ReactNode } from 'react'

interface Props {
    icon?: string | undefined
    title: string
    description?: string | undefined
    action?: ReactNode | undefined
}

export default function EmptyState({ icon = 'bi-inbox', title, description, action }: Props) {
    return (
        <div className="text-center py-5">
            <i className={`bi ${icon} fs-1 text-body-secondary d-block mb-3`} aria-hidden="true" />
            <h2 className="h6 mb-1">{title}</h2>
            {description ? <p className="text-body-secondary small mb-3">{description}</p> : null}
            {action}
        </div>
    )
}
