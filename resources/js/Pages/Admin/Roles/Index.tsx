import { Head, router } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatusBadge from '@/Components/StatusBadge'

interface PermissionCell {
    granted: boolean
    scope: string | null
}

interface RoleRow {
    id: string
    name: string
    is_system: boolean
    members: number
    is_own_role: boolean
    permissions: Record<string, PermissionCell>
}

interface Props {
    groups: { group: string; abilities: string[] }[]
    roles: RoleRow[]
    scopeOptions: { value: string; label: string }[]
    myScopes: Record<string, string | null>
    can: { update: boolean }
}

const RANK: Record<string, number> = { own: 0, team: 1, branch: 2, company: 3, all: 4 }

export default function RoleMatrix({ groups, roles, scopeOptions, myScopes, can }: Props) {
    const [roleId, setRoleId] = useState(roles[0]?.id ?? '')

    const role = roles.find((r) => r.id === roleId)

    const mayEdit = (permission: string, target: string): boolean => {
        if (!can.update || role === undefined || role.is_own_role) {
            return false
        }

        const mine = myScopes[permission]

        return mine !== null && mine !== undefined && RANK[mine]! >= RANK[target]!
    }

    const setScope = (permission: string, scope: string) => {
        router.post(`/admin/roles/${roleId}/scope`, { permission, scope }, { preserveScroll: true, preserveState: true })
    }

    return (
        <AppLayout>
            <Head title="Roles and reach" />

            <PageHeader
                title="Roles and reach"
                subtitle="A permission says what a role may do. Its reach says how much of the data that applies to."
            />

            <div className="card mb-3">
                <div className="card-body d-flex flex-wrap gap-2">
                    {roles.map((row) => (
                        <button
                            key={row.id}
                            type="button"
                            className={`btn btn-sm ${row.id === roleId ? 'btn-primary' : 'btn-outline-secondary'}`}
                            onClick={() => setRoleId(row.id)}
                        >
                            {row.name}
                            <span className="ms-2 badge text-bg-light">{row.members}</span>
                        </button>
                    ))}
                </div>
            </div>

            {role === undefined ? null : (
                <>
                    <div className="d-flex flex-wrap gap-2 mb-3">
                        {role.is_system ? <StatusBadge label="system role" tone="neutral" /> : null}
                        {role.is_own_role ? <StatusBadge label="you hold this role" tone="warning" /> : null}
                    </div>

                    {role.is_own_role ? (
                        <div className="alert alert-warning">
                            You hold this role yourself, so its reach is read-only here. Widening it would widen your own
                            access, which the server refuses.
                        </div>
                    ) : null}

                    <div className="alert alert-secondary small">
                        Which permissions a role carries is fixed by the role catalogue in code, so an upgrade cannot be
                        silently undone in the database. <strong>Reach is what you tune here</strong>, and never wider than
                        your own reach on the same permission.
                    </div>

                    <div className="d-flex flex-column gap-3">
                        {groups.map(({ group, abilities }) => (
                            <div key={group} className="card">
                                <div className="card-header bg-body"><h2 className="h6 mb-0 text-capitalize">{group}</h2></div>
                                <div className="card-body p-0">
                                    <div className="table-responsive">
                                        <table className="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col" style={{ width: '12rem' }}>Ability</th>
                                                    <th scope="col">Reach</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {abilities.map((ability) => {
                                                    const key = `${group}.${ability}`
                                                    const cell = role.permissions[key]

                                                    if (cell === undefined || !cell.granted) {
                                                        return (
                                                            <tr key={key} className="text-body-secondary">
                                                                <td>{ability}</td>
                                                                <td className="small">not carried by this role</td>
                                                            </tr>
                                                        )
                                                    }

                                                    return (
                                                        <tr key={key}>
                                                            <td>{ability}</td>
                                                            <td>
                                                                <div className="d-flex flex-wrap gap-1">
                                                                    {scopeOptions.map((option) => {
                                                                        const active = cell.scope === option.value
                                                                        const allowed = mayEdit(key, option.value)

                                                                        return (
                                                                            <button
                                                                                key={option.value}
                                                                                type="button"
                                                                                className={`btn btn-sm ${active ? 'btn-primary' : 'btn-outline-secondary'}`}
                                                                                disabled={!allowed && !active}
                                                                                title={allowed || active ? option.label : 'Wider than your own reach on this permission.'}
                                                                                onClick={() => setScope(key, option.value)}
                                                                            >
                                                                                {option.label}
                                                                            </button>
                                                                        )
                                                                    })}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    )
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </>
            )}
        </AppLayout>
    )
}
