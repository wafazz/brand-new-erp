import { usePage } from '@inertiajs/react'

interface Props {
    exportKey: string
    ability: string
    label?: string
}

/**
 * Downloads a CSV of whatever the current role and data scope permit.
 *
 * The button is hidden without the ability, but the server refuses the request
 * regardless — hiding it here is for tidiness, not for security.
 */
export default function ExportButton({ exportKey, ability, label = 'Export CSV' }: Props) {
    const page = usePage<{ auth: { can: string[] } }>()

    if (!page.props.auth.can.includes(ability)) {
        return null
    }

    return (
        <a href={`/exports/${exportKey}`} className="btn btn-sm btn-outline-secondary" download>
            <i className="bi bi-download me-1" aria-hidden="true" />
            {label}
        </a>
    )
}
