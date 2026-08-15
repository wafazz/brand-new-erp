import { router } from '@inertiajs/react'
import { useState, type FormEvent } from 'react'

interface Props {
    action: string
    initial?: string | undefined
    placeholder?: string | undefined
}

export default function SearchBar({ action, initial = '', placeholder = 'Search…' }: Props) {
    const [term, setTerm] = useState(initial)

    const submit = (event: FormEvent) => {
        event.preventDefault()
        router.get(action, term === '' ? {} : { q: term }, { preserveState: true, replace: true })
    }

    return (
        <form onSubmit={submit} className="d-flex gap-2" role="search">
            <input
                type="search"
                className="form-control form-control-sm"
                value={term}
                placeholder={placeholder}
                onChange={(e) => setTerm(e.target.value)}
                style={{ maxWidth: '18rem' }}
            />
            <button type="submit" className="btn btn-sm btn-outline-secondary">
                <i className="bi bi-search" aria-hidden="true" />
            </button>
        </form>
    )
}
