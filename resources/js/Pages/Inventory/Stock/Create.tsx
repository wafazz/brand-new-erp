import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import FormField from '@/Components/FormField'
import SelectInput from '@/Components/SelectInput'
import TextInput from '@/Components/TextInput'

interface Props {
    warehouses: { value: string; label: string }[]
    variants: { value: string; label: string }[]
}

export default function StockCreate({ warehouses, variants }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        warehouse_id: warehouses[0]?.value ?? '',
        product_variant_id: '',
        low_stock_threshold: '',
    })

    return (
        <AppLayout>
            <Head title="Open a stock line" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/inventory')
                }}
            >
                <PageHeader
                    title="Open a stock line"
                    subtitle="A stock line is one variant in one warehouse. Quantities are moved through adjustments, never typed in directly."
                    actions={
                        <>
                            <Link href="/inventory" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Opening…' : 'Open line'}
                            </button>
                        </>
                    }
                />

                <div className="card" style={{ maxWidth: '40rem' }}>
                    <div className="card-body">
                        <FormField label="Warehouse" name="warehouse_id" required error={errors.warehouse_id}>
                            <SelectInput name="warehouse_id" value={data.warehouse_id} options={warehouses} onChange={(v) => setData('warehouse_id', v)} />
                        </FormField>

                        <FormField label="Variant" name="product_variant_id" required error={errors.product_variant_id}>
                            <SelectInput
                                name="product_variant_id"
                                value={data.product_variant_id}
                                options={variants}
                                placeholder="Choose a variant…"
                                onChange={(v) => setData('product_variant_id', v)}
                            />
                        </FormField>

                        <FormField label="Low stock threshold" name="low_stock_threshold" error={errors.low_stock_threshold} hint="Leave empty for no alert.">
                            <TextInput name="low_stock_threshold" value={data.low_stock_threshold} onChange={(v) => setData('low_stock_threshold', v)} />
                        </FormField>
                    </div>
                </div>
            </form>
        </AppLayout>
    )
}
