import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'
import SelectInput from '@/Components/SelectInput'

export interface VariantValues {
    id?: string
    sku: string
    name: string
    barcode: string
    cost_price: string
    selling_price: string
    is_default: boolean
    is_active: boolean
}

export interface ProductFormValues {
    sku: string
    name: string
    type: string
    status: string
    description: string
    category_id: string
    brand_id: string
    unit_of_measure_id: string
    tax_rate_id: string
    is_stock_tracked: boolean
    variants: VariantValues[]
}

export const EMPTY_VARIANT: VariantValues = {
    sku: '',
    name: 'Default',
    barcode: '',
    cost_price: '0',
    selling_price: '0',
    is_default: true,
    is_active: true,
}

export const EMPTY_PRODUCT: ProductFormValues = {
    sku: '',
    name: '',
    type: 'product',
    status: 'active',
    description: '',
    category_id: '',
    brand_id: '',
    unit_of_measure_id: '',
    tax_rate_id: '',
    is_stock_tracked: true,
    variants: [{ ...EMPTY_VARIANT }],
}

export interface Reference {
    value: string
    label: string
}

interface Props {
    values: ProductFormValues
    errors: Partial<Record<string, string>>
    categories: Reference[]
    brands: Reference[]
    units: Reference[]
    taxRates: Reference[]
    onChange: <K extends keyof ProductFormValues>(key: K, value: ProductFormValues[K]) => void
}

export default function ProductForm({ values, errors, categories, brands, units, taxRates, onChange }: Props) {
    const setVariant = (index: number, patch: Partial<VariantValues>) => {
        onChange(
            'variants',
            values.variants.map((variant, i) => (i === index ? { ...variant, ...patch } : variant))
        )
    }

    const addVariant = () => {
        onChange('variants', [
            ...values.variants,
            { ...EMPTY_VARIANT, name: `Variant ${values.variants.length + 1}`, is_default: false },
        ])
    }

    const removeVariant = (index: number) => {
        if (values.variants.length === 1) {
            return
        }

        onChange('variants', values.variants.filter((_, i) => i !== index))
    }

    return (
        <>
            <div className="row g-3">
                <div className="col-12 col-lg-7">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Identity</h2></div>
                        <div className="card-body">
                            <FormField label="Name" name="name" required error={errors.name}>
                                <TextInput name="name" value={values.name} invalid={Boolean(errors.name)} onChange={(v) => onChange('name', v)} />
                            </FormField>

                            <div className="row">
                                <div className="col-md-6">
                                    <FormField label="SKU" name="sku" required error={errors.sku} hint="Unique within this company.">
                                        <TextInput name="sku" value={values.sku} invalid={Boolean(errors.sku)} onChange={(v) => onChange('sku', v)} />
                                    </FormField>
                                </div>
                                <div className="col-md-3">
                                    <FormField label="Type" name="type" required error={errors.type}>
                                        <SelectInput
                                            name="type"
                                            value={values.type}
                                            invalid={Boolean(errors.type)}
                                            options={[
                                                { value: 'product', label: 'Product' },
                                                { value: 'service', label: 'Service' },
                                                { value: 'bundle', label: 'Bundle' },
                                            ]}
                                            onChange={(v) => onChange('type', v)}
                                        />
                                    </FormField>
                                </div>
                                <div className="col-md-3">
                                    <FormField label="Status" name="status" required error={errors.status}>
                                        <SelectInput
                                            name="status"
                                            value={values.status}
                                            invalid={Boolean(errors.status)}
                                            options={[
                                                { value: 'active', label: 'Active' },
                                                { value: 'inactive', label: 'Inactive' },
                                                { value: 'discontinued', label: 'Discontinued' },
                                            ]}
                                            onChange={(v) => onChange('status', v)}
                                        />
                                    </FormField>
                                </div>
                            </div>

                            <FormField label="Description" name="description" error={errors.description}>
                                <textarea
                                    id="description"
                                    name="description"
                                    className="form-control"
                                    rows={3}
                                    value={values.description}
                                    onChange={(e) => onChange('description', e.target.value)}
                                />
                            </FormField>
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-5">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Classification</h2></div>
                        <div className="card-body">
                            <FormField label="Category" name="category_id" error={errors.category_id}>
                                <SelectInput name="category_id" value={values.category_id} options={categories} placeholder="Unclassified" onChange={(v) => onChange('category_id', v)} />
                            </FormField>

                            <FormField label="Brand" name="brand_id" error={errors.brand_id}>
                                <SelectInput name="brand_id" value={values.brand_id} options={brands} placeholder="No brand" onChange={(v) => onChange('brand_id', v)} />
                            </FormField>

                            <FormField label="Unit of measure" name="unit_of_measure_id" error={errors.unit_of_measure_id}>
                                <SelectInput name="unit_of_measure_id" value={values.unit_of_measure_id} options={units} placeholder="Each" onChange={(v) => onChange('unit_of_measure_id', v)} />
                            </FormField>

                            <FormField label="Tax rate" name="tax_rate_id" error={errors.tax_rate_id}>
                                <SelectInput name="tax_rate_id" value={values.tax_rate_id} options={taxRates} placeholder="No tax" onChange={(v) => onChange('tax_rate_id', v)} />
                            </FormField>

                            <div className="form-check">
                                <input
                                    id="is_stock_tracked"
                                    className="form-check-input"
                                    type="checkbox"
                                    checked={values.is_stock_tracked}
                                    onChange={(e) => onChange('is_stock_tracked', e.target.checked)}
                                />
                                <label className="form-check-label" htmlFor="is_stock_tracked">
                                    Track stock for this product
                                </label>
                                <div className="form-text">Turn this off for services and anything you never count.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card mt-3">
                <div className="card-header bg-body d-flex align-items-center justify-content-between">
                    <h2 className="h6 mb-0">Variants</h2>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={addVariant}>Add variant</button>
                </div>
                <div className="card-body">
                    {errors.variants ? <div className="alert alert-danger py-2">{errors.variants}</div> : null}

                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">SKU</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Barcode</th>
                                    <th scope="col" className="text-end">Cost</th>
                                    <th scope="col" className="text-end">Price</th>
                                    <th scope="col" className="text-center">Default</th>
                                    <th scope="col" className="text-center">Active</th>
                                    <th scope="col" />
                                </tr>
                            </thead>
                            <tbody>
                                {values.variants.map((variant, index) => (
                                    <tr key={variant.id ?? `new-${index}`}>
                                        <td>
                                            <input
                                                className={`form-control form-control-sm ${errors[`variants.${index}.sku`] ? 'is-invalid' : ''}`}
                                                value={variant.sku}
                                                onChange={(e) => setVariant(index, { sku: e.target.value })}
                                                aria-label={`Variant ${index + 1} SKU`}
                                            />
                                        </td>
                                        <td>
                                            <input
                                                className={`form-control form-control-sm ${errors[`variants.${index}.name`] ? 'is-invalid' : ''}`}
                                                value={variant.name}
                                                onChange={(e) => setVariant(index, { name: e.target.value })}
                                                aria-label={`Variant ${index + 1} name`}
                                            />
                                        </td>
                                        <td>
                                            <input
                                                className="form-control form-control-sm"
                                                value={variant.barcode}
                                                onChange={(e) => setVariant(index, { barcode: e.target.value })}
                                                aria-label={`Variant ${index + 1} barcode`}
                                            />
                                        </td>
                                        <td>
                                            <input
                                                className={`form-control form-control-sm text-end font-monospace ${errors[`variants.${index}.cost_price`] ? 'is-invalid' : ''}`}
                                                value={variant.cost_price}
                                                inputMode="decimal"
                                                onChange={(e) => setVariant(index, { cost_price: e.target.value })}
                                                aria-label={`Variant ${index + 1} cost`}
                                            />
                                        </td>
                                        <td>
                                            <input
                                                className={`form-control form-control-sm text-end font-monospace ${errors[`variants.${index}.selling_price`] ? 'is-invalid' : ''}`}
                                                value={variant.selling_price}
                                                inputMode="decimal"
                                                onChange={(e) => setVariant(index, { selling_price: e.target.value })}
                                                aria-label={`Variant ${index + 1} price`}
                                            />
                                        </td>
                                        <td className="text-center">
                                            <input
                                                type="radio"
                                                className="form-check-input"
                                                name="default_variant"
                                                checked={variant.is_default}
                                                onChange={() => onChange('variants', values.variants.map((v, i) => ({ ...v, is_default: i === index })))}
                                                aria-label={`Make variant ${index + 1} the default`}
                                            />
                                        </td>
                                        <td className="text-center">
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                checked={variant.is_active}
                                                onChange={(e) => setVariant(index, { is_active: e.target.checked })}
                                                aria-label={`Variant ${index + 1} active`}
                                            />
                                        </td>
                                        <td className="text-end">
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-danger"
                                                disabled={values.variants.length === 1}
                                                onClick={() => removeVariant(index)}
                                                aria-label={`Remove variant ${index + 1}`}
                                            >
                                                <i className="bi bi-x-lg" aria-hidden="true" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <p className="form-text mb-0">
                        A variant removed here is deactivated, never deleted. Orders, stock movements and costing
                        history keep pointing at it.
                    </p>
                </div>
            </div>
        </>
    )
}
