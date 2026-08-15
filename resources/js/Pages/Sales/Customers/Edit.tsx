import { Head, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import CustomerForm, { type CustomerFormValues } from './Form'

interface Props {
    customer: CustomerFormValues & { id: string }
    groups: { value: string; label: string }[]
}

export default function CustomerEdit({ customer, groups }: Props) {
    const { data, setData, put, processing, errors } = useForm<CustomerFormValues>({
        code: customer.code ?? '',
        name: customer.name ?? '',
        type: customer.type ?? 'individual',
        company_name: customer.company_name ?? '',
        email: customer.email ?? '',
        phone: customer.phone ?? '',
        tax_no: customer.tax_no ?? '',
        status: customer.status ?? 'active',
        credit_limit: customer.credit_limit ?? '0',
        payment_terms_days: customer.payment_terms_days ?? '0',
        customer_group_id: customer.customer_group_id ?? '',
        notes: customer.notes ?? '',
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        put(`/customers/${customer.id}`)
    }

    return (
        <AppLayout>
            <Head title={`Edit ${customer.name}`} />
            <PageHeader title={`Edit ${customer.name}`} subtitle={customer.code} />

            <form onSubmit={submit} noValidate>
                <CustomerForm values={data} errors={errors} groups={groups} onChange={(k, v) => setData((previous) => ({ ...previous, [k]: v }))} />

                <div className="d-flex gap-2 mt-3">
                    <button type="submit" className="btn btn-primary" disabled={processing}>
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                    <a href={`/customers/${customer.id}`} className="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </AppLayout>
    )
}
