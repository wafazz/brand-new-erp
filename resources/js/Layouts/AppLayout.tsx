import type { ReactNode } from 'react'
import { Link } from '@inertiajs/react'
import { useAuth } from '@/Hooks/useAuth'

interface NavItem {
    label: string
    href: string
    icon: string
}

interface Props {
    children: ReactNode
    navigation?: NavItem[] | undefined
}

export default function AppLayout({ children, navigation = [] }: Props) {
    const { user, company } = useAuth()

    return (
        <div className="app-shell d-flex">
            <aside className="app-sidebar bg-body border-end">
                <div className="p-3 border-bottom">
                    <div className="fw-semibold text-truncate">{company?.name ?? 'SME ERP'}</div>
                    <div className="small text-body-secondary">{user?.name}</div>
                </div>
                <nav className="nav flex-column p-2">
                    {navigation.map((item) => (
                        <Link key={item.href} href={item.href} className="nav-link d-flex align-items-center gap-2">
                            <i className={`bi ${item.icon}`} aria-hidden="true" />
                            {item.label}
                        </Link>
                    ))}
                </nav>
            </aside>
            <main className="flex-grow-1 p-4">{children}</main>
        </div>
    )
}
