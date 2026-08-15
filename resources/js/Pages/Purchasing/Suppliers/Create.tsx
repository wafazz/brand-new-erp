import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import SupplierForm, { EMPTY_SUPPLIER, type SupplierFormValues } from './Form'

export default function SupplierCreate() {
    const { data, setData, post, processing, errors } = useForm<SupplierFormValues>({ ...EMPTY_SUPPLIER })

    return (
        <AppLayout>
            <Head title="New supplier" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/suppliers')
                }}
            >
                <PageHeader
                    title="New supplier"
                    actions={
                        <>
                            <Link href="/suppliers" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Create supplier'}
                            </button>
                        </>
                    }
                />

                <SupplierForm values={data} errors={errors} onChange={(key, value) => setData((previous) => ({ ...previous, [key]: value }))} />
            </form>
        </AppLayout>
    )
}
