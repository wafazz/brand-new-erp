interface Option {
    value: string
    label: string
}

interface Props {
    name: string
    value: string
    options: Option[]
    onChange: (value: string) => void
    placeholder?: string | undefined
    invalid?: boolean | undefined
}

export default function SelectInput({ name, value, options, onChange, placeholder, invalid = false }: Props) {
    return (
        <select
            id={name}
            name={name}
            className={`form-select ${invalid ? 'is-invalid' : ''}`}
            value={value}
            onChange={(e) => onChange(e.target.value)}
        >
            {placeholder ? <option value="">{placeholder}</option> : null}
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    )
}
