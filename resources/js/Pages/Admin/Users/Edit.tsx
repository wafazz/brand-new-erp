import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import MemberForm, { type MemberFormValues, type Reference, type RoleOption } from './Form'

interface Props {
    member: {
        id: string
        name: string | null
        email: string | null
        role: string | null
        branch_id: string | null
        department_id: string | null
        employee_no: string | null
        is_active: boolean
        is_self: boolean
    }
    roles: RoleOption[]
    branches: Reference[]
    departments: Reference[]
}

export default function UserEdit({ member, roles, branches, departments }: Props) {
    const { data, setData, put, processing, errors } = useForm<MemberFormValues>({
        name: member.name ?? '',
        email: member.email ?? '',
        password: '',
        role: member.role ?? '',
        branch_id: member.branch_id ?? '',
        department_id: member.department_id ?? '',
        employee_no: member.employee_no ?? '',
        is_active: member.is_active,
    })

    return (
        <AppLayout>
            <Head title={`Access · ${member.name ?? 'Member'}`} />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    put(`/admin/users/${member.id}`)
                }}
            >
                <PageHeader
                    title={member.name ?? 'Member'}
                    subtitle={member.email ?? undefined}
                    actions={
                        <>
                            <Link href="/admin/users" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing || member.is_self}>
                                {processing ? 'Saving…' : 'Save access'}
                            </button>
                        </>
                    }
                />

                {member.is_self ? (
                    <div className="alert alert-warning">
                        This is your own account. You cannot change your own role or reach — ask another administrator.
                    </div>
                ) : null}

                <MemberForm
                    values={data}
                    errors={errors}
                    roles={roles}
                    branches={branches}
                    departments={departments}
                    withAccount={false}
                    onChange={(key, value) => setData((previous) => ({ ...previous, [key]: value }))}
                />
            </form>
        </AppLayout>
    )
}
