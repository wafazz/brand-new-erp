import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import MemberForm, { EMPTY_MEMBER, type MemberFormValues, type Reference, type RoleOption } from './Form'

interface Props {
    roles: RoleOption[]
    branches: Reference[]
}

export default function UserCreate({ roles, branches }: Props) {
    const { data, setData, post, processing, errors } = useForm<MemberFormValues>({ ...EMPTY_MEMBER })

    return (
        <AppLayout>
            <Head title="Add person" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/admin/users')
                }}
            >
                <PageHeader
                    title="Add person"
                    subtitle="Creates the sign-in account and their membership of this company in one step."
                    actions={
                        <>
                            <Link href="/admin/users" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Add person'}
                            </button>
                        </>
                    }
                />

                <MemberForm
                    values={data}
                    errors={errors}
                    roles={roles}
                    branches={branches}
                    withAccount
                    onChange={(key, value) => setData((previous) => ({ ...previous, [key]: value }))}
                />
            </form>
        </AppLayout>
    )
}
