import { Head, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import CustomerForm, { EMPTY_CUSTOMER, type CustomerFormValues } from './Form'

interface Props {
    groups: { value: string; label: string }[]
}

export default function CustomerCreate({ groups }: Props) {
    const { data, setData, post, processing, errors } = useForm<CustomerFormValues>(EMPTY_CUSTOMER)

    const submit = (event: FormEvent) => {
        event.preventDefault()
        post('/customers')
    }

    return (
        <AppLayout>
            <Head title="New customer" />
            <PageHeader title="New customer" subtitle="You will be recorded as the owner of this record." />

            <form onSubmit={submit} noValidate>
                <CustomerForm values={data} errors={errors} groups={groups} onChange={(k, v) => setData((previous) => ({ ...previous, [k]: v }))} />

                <div className="d-flex gap-2 mt-3">
                    <button type="submit" className="btn btn-primary" disabled={processing}>
                        {processing ? 'Saving…' : 'Create customer'}
                    </button>
                    <a href="/customers" className="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </AppLayout>
    )
}
