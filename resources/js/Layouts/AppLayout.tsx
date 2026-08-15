import { useState, type ReactNode } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { useAuth } from '@/Hooks/useAuth'
import type { SharedProps } from '@/Types'

interface Props {
    children: ReactNode
}

export default function AppLayout({ children }: Props) {
    const { user, company } = useAuth()
    const page = usePage<SharedProps>()
    const { navigation, flash } = page.props
    const current = page.url
    const [open, setOpen] = useState(false)

    return (
        <div className="app-shell d-flex">
            <aside className={`app-sidebar bg-body border-end ${open ? 'app-sidebar-open' : ''}`}>
                <div className="p-3 border-bottom">
                    <div className="fw-semibold text-truncate">{company?.name ?? 'SME ERP'}</div>
                    <div className="small text-body-secondary text-truncate">{user?.name}</div>
                </div>

                <nav className="p-2 overflow-auto">
                    {navigation.map((group) => (
                        <div key={group.group} className="mb-3">
                            <div className="text-uppercase small fw-semibold text-body-secondary px-2 mb-1">
                                {group.group}
                            </div>
                            {group.items.map((item) => {
                                const active = current === item.href || current.startsWith(`${item.href}/`)

                                return (
                                    <Link
                                        key={item.key}
                                        href={item.href}
                                        className={`nav-link d-flex align-items-center gap-2 rounded px-2 py-1 ${active ? 'active bg-primary-subtle fw-semibold' : ''}`}
                                    >
                                        <i className={`bi ${item.icon}`} aria-hidden="true" />
                                        {item.label}
                                    </Link>
                                )
                            })}
                        </div>
                    ))}
                </nav>

                <div className="p-2 border-top mt-auto d-grid gap-2">
                    <Link
                        href="/profile"
                        className={`btn btn-sm btn-outline-secondary text-start ${current.startsWith('/profile') ? 'active' : ''}`}
                    >
                        <i className="bi bi-person-circle me-1" aria-hidden="true" />
                        {user?.name ?? 'Your profile'}
                    </Link>
                    <Link href="/logout" method="post" as="button" className="btn btn-sm btn-outline-secondary w-100">
                        <i className="bi bi-box-arrow-right me-1" aria-hidden="true" />
                        Sign out
                    </Link>
                </div>
            </aside>

            <main className="flex-grow-1 min-vw-0">
                <div className="d-lg-none border-bottom bg-body p-2">
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setOpen(!open)}>
                        <i className="bi bi-list" aria-hidden="true" /> Menu
                    </button>
                </div>

                <div className="p-4">
                    {flash.success ? (
                        <div className="alert alert-success alert-dismissible" role="alert">
                            {flash.success}
                        </div>
                    ) : null}
                    {flash.error ? (
                        <div className="alert alert-danger alert-dismissible" role="alert">
                            {flash.error}
                        </div>
                    ) : null}

                    {children}
                </div>
            </main>
        </div>
    )
}
