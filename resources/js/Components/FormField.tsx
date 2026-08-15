import type { ReactNode } from 'react'

interface Props {
    label: string
    name: string
    error?: string | undefined
    hint?: string | undefined
    required?: boolean | undefined
    children: ReactNode
}

export default function FormField({ label, name, error, hint, required = false, children }: Props) {
    return (
        <div className="mb-3">
            <label className="form-label" htmlFor={name}>
                {label}
                {required ? <span className="text-danger ms-1">*</span> : null}
            </label>
            {children}
            {hint && !error ? <div className="form-text">{hint}</div> : null}
            {error ? <div className="invalid-feedback d-block">{error}</div> : null}
        </div>
    )
}
