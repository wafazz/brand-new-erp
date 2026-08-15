interface Props {
    label: string
    value: string
    hint?: string | undefined
    tone?: 'default' | 'success' | 'danger' | 'warning' | undefined
}

const toneClass: Record<NonNullable<Props['tone']>, string> = {
    default: 'text-body',
    success: 'text-success',
    danger: 'text-danger',
    warning: 'text-warning',
}

export default function StatCard({ label, value, hint, tone = 'default' }: Props) {
    return (
        <div className="card h-100">
            <div className="card-body">
                <div className="text-body-secondary text-uppercase small fw-semibold mb-2">{label}</div>
                <div className={`h3 mb-0 ${toneClass[tone]}`}>{value}</div>
                {hint ? <div className="small text-body-secondary mt-2">{hint}</div> : null}
            </div>
        </div>
    )
}
