import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import ProductForm, { type ProductFormValues, type Reference, type VariantValues } from './Form'

interface Props {
    product: {
        id: string
        sku: string
        name: string
        type: string
        status: string
        description: string | null
        category_id: string | null
        brand_id: string | null
        unit_of_measure_id: string | null
        tax_rate_id: string | null
        is_stock_tracked: boolean
        variants: (VariantValues & { id: string; barcode: string | null })[]
    }
    categories: Reference[]
    brands: Reference[]
    units: Reference[]
    taxRates: Reference[]
}

export default function ProductEdit({ product, categories, brands, units, taxRates }: Props) {
    const { data, setData, put, processing, errors } = useForm<ProductFormValues>({
        sku: product.sku,
        name: product.name,
        type: product.type,
        status: product.status,
        description: product.description ?? '',
        category_id: product.category_id ?? '',
        brand_id: product.brand_id ?? '',
        unit_of_measure_id: product.unit_of_measure_id ?? '',
        tax_rate_id: product.tax_rate_id ?? '',
        is_stock_tracked: product.is_stock_tracked,
        variants: product.variants.map((variant) => ({ ...variant, barcode: variant.barcode ?? '' })),
    })

    return (
        <AppLayout>
            <Head title={`Edit ${product.name}`} />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    put(`/products/${product.id}`)
                }}
            >
                <PageHeader
                    title={`Edit ${product.name}`}
                    subtitle={product.sku}
                    actions={
                        <>
                            <Link href={`/products/${product.id}`} className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Save changes'}
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
