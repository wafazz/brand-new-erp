import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import SupplierForm, { type SupplierFormValues } from './Form'

interface Props {
    supplier: SupplierFormValues & { id: string; code: string | null; registration_no: string | null; tax_no: string | null; email: string | null; phone: string | null; notes: string | null }
}

export default function SupplierEdit({ supplier }: Props) {
    const { data, setData, put, processing, errors } = useForm<SupplierFormValues>({
        code: supplier.code ?? '',
        name: supplier.name,
        registration_no: supplier.registration_no ?? '',
        tax_no: supplier.tax_no ?? '',
        email: supplier.email ?? '',
        phone: supplier.phone ?? '',
        currency: supplier.currency,
        credit_limit: supplier.credit_limit,
        payment_terms_days: supplier.payment_terms_days,
        status: supplier.status,
        notes: supplier.notes ?? '',
    })

    return (
        <AppLayout>
            <Head title={`Edit ${supplier.name}`} />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    put(`/suppliers/${supplier.id}`)
                }}
            >
                <PageHeader
                    title={`Edit ${supplier.name}`}
                    subtitle={supplier.code ?? undefined}
                    actions={
                        <>
                            <Link href={`/suppliers/${supplier.id}`} className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Save changes'}
                            </button>
                        </>
                    }
                />

                <SupplierForm values={data} errors={errors} onChange={(key, value) => setData((previous) => ({ ...previous, [key]: value }))} />
            </form>
        </AppLayout>
    )
}
