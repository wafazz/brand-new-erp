interface Props {
    name: string
    value: string
    onChange: (value: string) => void
    type?: string | undefined
    placeholder?: string | undefined
    invalid?: boolean | undefined
    disabled?: boolean | undefined
}

export default function TextInput({ name, value, onChange, type = 'text', placeholder, invalid = false, disabled = false }: Props) {
    return (
        <input
            id={name}
            name={name}
            type={type}
            className={`form-control ${invalid ? 'is-invalid' : ''}`}
            value={value}
            placeholder={placeholder ?? ''}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
        />
    )
}
