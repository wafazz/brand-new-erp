import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import ProductForm, { EMPTY_PRODUCT, type ProductFormValues, type Reference } from './Form'

interface Props {
    categories: Reference[]
    brands: Reference[]
    units: Reference[]
    taxRates: Reference[]
}

export default function ProductCreate({ categories, brands, units, taxRates }: Props) {
    const { data, setData, post, processing, errors } = useForm<ProductFormValues>({ ...EMPTY_PRODUCT })

    return (
        <AppLayout>
            <Head title="New product" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/products')
                }}
            >
                <PageHeader
                    title="New product"
                    subtitle="Every product needs at least one variant — that is what stock and prices attach to."
                    actions={
                        <>
                            <Link href="/products" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Create product'}
                            </button>
                        </>
                    }
                />

                <ProductForm
                    values={data}
                    errors={errors}
                    categories={categories}
                    brands={brands}
                    units={units}
                    taxRates={taxRates}
                    onChange={(key, value) => setData((previous) => ({ ...previous, [key]: value }))}
                />
            </form>
        </AppLayout>
    )
}
