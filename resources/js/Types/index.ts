export type Ulid = string & { readonly __brand: 'Ulid' }
export type MoneyString = string & { readonly __brand: 'Money' }

export interface AuthUser {
    id: Ulid
    name: string
    email: string
}

export interface CompanySummary {
    id: Ulid
    name: string
    currency: string
}

export interface NavItem {
    key: string
    label: string
    icon: string
    href: string
}

export interface NavGroup {
    group: string
    items: NavItem[]
}

export interface Paginated<T> {
    data: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
}

export interface SharedProps {
    auth: {
        user: AuthUser | null
        can: string[]
    }
    company: CompanySummary | null
    navigation: NavGroup[]
    flash: {
        success: string | null
        error: string | null
    }
    [key: string]: unknown
}
