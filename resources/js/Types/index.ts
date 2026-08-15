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

export interface SharedProps {
    auth: {
        user: AuthUser | null
        can: string[]
    }
    company: CompanySummary | null
    flash: {
        success: string | null
        error: string | null
    }
    [key: string]: unknown
}
