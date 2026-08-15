import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'
import SelectInput from '@/Components/SelectInput'

export interface SupplierFormValues {
    code: string
    name: string
    registration_no: string
    tax_no: string
    email: string
    phone: string
    currency: string
    credit_limit: string
    payment_terms_days: string
    status: string
    notes: string
}

export const EMPTY_SUPPLIER: SupplierFormValues = {
    code: '',
    name: '',
    registration_no: '',
    tax_no: '',
    email: '',
    phone: '',
    currency: 'MYR',
    credit_limit: '0',
    payment_terms_days: '30',
    status: 'active',
    notes: '',
}

interface Props {
    values: SupplierFormValues
    errors: Partial<Record<string, string>>
    onChange: <K extends keyof SupplierFormValues>(key: K, value: SupplierFormValues[K]) => void
}

export default function SupplierForm({ values, errors, onChange }: Props) {
    return (
        <div className="row g-3">
            <div className="col-12 col-lg-6">
                <div className="card h-100">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Identity</h2></div>
                    <div className="card-body">
                        <FormField label="Name" name="name" required error={errors.name}>
                            <TextInput name="name" value={values.name} invalid={Boolean(errors.name)} onChange={(v) => onChange('name', v)} />
                        </FormField>

                        <FormField label="Code" name="code" error={errors.code} hint="Left empty, one is allocated for you.">
                            <TextInput name="code" value={values.code} invalid={Boolean(errors.code)} onChange={(v) => onChange('code', v)} />
                        </FormField>

                        <div className="row">
                            <div className="col-md-6">
                                <FormField label="Registration no." name="registration_no" error={errors.registration_no}>
                                    <TextInput name="registration_no" value={values.registration_no} onChange={(v) => onChange('registration_no', v)} />
                                </FormField>
                            </div>
                            <div className="col-md-6">
                                <FormField label="Tax no." name="tax_no" error={errors.tax_no}>
                                    <TextInput name="tax_no" value={values.tax_no} onChange={(v) => onChange('tax_no', v)} />
                                </FormField>
                            </div>
                        </div>

                        <FormField label="Status" name="status" required error={errors.status}>
                            <SelectInput
                                name="status"
                                value={values.status}
                                invalid={Boolean(errors.status)}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                    { value: 'blocked', label: 'Blocked' },
                                ]}
                                onChange={(v) => onChange('status', v)}
                            />
                        </FormField>
                    </div>
                </div>
            </div>

            <div className="col-12 col-lg-6">
                <div className="card h-100">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Contact and terms</h2></div>
                    <div className="card-body">
                        <div className="row">
                            <div className="col-md-6">
                                <FormField label="Email" name="email" error={errors.email}>
                                    <TextInput name="email" type="email" value={values.email} invalid={Boolean(errors.email)} onChange={(v) => onChange('email', v)} />
                                </FormField>
                            </div>
                            <div className="col-md-6">
                                <FormField label="Phone" name="phone" error={errors.phone}>
                                    <TextInput name="phone" value={values.phone} onChange={(v) => onChange('phone', v)} />
                                </FormField>
                            </div>
                        </div>

                        <div className="row">
                            <div className="col-md-4">
                                <FormField label="Currency" name="currency" required error={errors.currency}>
                                    <TextInput name="currency" value={values.currency} invalid={Boolean(errors.currency)} onChange={(v) => onChange('currency', v.toUpperCase())} />
                                </FormField>
                            </div>
                            <div className="col-md-4">
                                <FormField label="Credit limit" name="credit_limit" required error={errors.credit_limit}>
                                    <TextInput name="credit_limit" value={values.credit_limit} invalid={Boolean(errors.credit_limit)} onChange={(v) => onChange('credit_limit', v)} />
                                </FormField>
                            </div>
                            <div className="col-md-4">
                                <FormField label="Terms (days)" name="payment_terms_days" required error={errors.payment_terms_days}>
                                    <TextInput name="payment_terms_days" value={values.payment_terms_days} invalid={Boolean(errors.payment_terms_days)} onChange={(v) => onChange('payment_terms_days', v)} />
                                </FormField>
                            </div>
                        </div>

                        <FormField label="Notes" name="notes" error={errors.notes}>
                            <textarea
                                id="notes"
                                name="notes"
                                className="form-control"
                                rows={3}
                                value={values.notes}
                                onChange={(e) => onChange('notes', e.target.value)}
                            />
                        </FormField>
                    </div>
                </div>
            </div>
        </div>
    )
}
