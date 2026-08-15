type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

interface Props {
    label: string
    tone?: Tone | undefined
}

const toneClass: Record<Tone, string> = {
    neutral: 'text-bg-secondary',
    info: 'text-bg-info',
    success: 'text-bg-success',
    warning: 'text-bg-warning',
    danger: 'text-bg-danger',
}

export default function StatusBadge({ label, tone = 'neutral' }: Props) {
    return <span className={`badge rounded-pill ${toneClass[tone]}`}>{label}</span>
}
