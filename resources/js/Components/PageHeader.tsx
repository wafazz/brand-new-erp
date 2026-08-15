import type { ReactNode } from 'react'

interface Props {
    title: string
    subtitle?: string | undefined
    actions?: ReactNode | undefined
}

export default function PageHeader({ title, subtitle, actions }: Props) {
    return (
        <div className="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
            <div>
                <h1 className="h4 mb-1">{title}</h1>
                {subtitle ? <p className="text-body-secondary mb-0">{subtitle}</p> : null}
            </div>
            {actions ? <div className="d-flex gap-2">{actions}</div> : null}
        </div>
    )
}
