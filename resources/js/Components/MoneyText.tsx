import type { MoneyString } from '@/Types'

interface Props {
    amount: MoneyString | string
    currency: string
    muted?: boolean | undefined
}

export default function MoneyText({ amount, currency, muted = false }: Props) {
    const value = Number(amount)
    const formatted = Number.isFinite(value)
        ? value.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : amount

    return (
        <span className={`font-monospace ${muted ? 'text-body-secondary' : ''} ${value < 0 ? 'text-danger' : ''}`}>
            {currency} {formatted}
        </span>
    )
}
