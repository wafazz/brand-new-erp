import { usePage } from '@inertiajs/react'
import type { SharedProps } from '@/Types'

export function useAuth() {
    const { auth, company } = usePage<SharedProps>().props

    const can = (permission: string): boolean => auth.can.includes(permission)

    const canAny = (...permissions: string[]): boolean => permissions.some(can)

    return { user: auth.user, company, can, canAny }
}
