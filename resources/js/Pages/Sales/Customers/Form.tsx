import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'
import SelectInput from '@/Components/SelectInput'

export interface CustomerFormValues {
    code: string
    name: string
    type: string
    company_name: string
    email: string
    phone: string
    tax_no: string
    status: string
    credit_limit: string
    payment_terms_days: string
    customer_group_id: string
    notes: string
}

export const EMPTY_CUSTOMER: CustomerFormValues = {
    code: '',
    name: '',
    type: 'individual',
    company_name: '',
    email: '',
    phone: '',
    tax_no: '',
    status: 'active',
    credit_limit: '0',
    payment_terms_days: '0',
    customer_group_id: '',
    notes: '',
}

interface Props {
    values: CustomerFormValues
    errors: Partial<Record<keyof CustomerFormValues, string>>
    groups: { value: string; label: string }[]
    onChange: <K extends keyof CustomerFormValues>(key: K, value: CustomerFormValues[K]) => void
}

export default function CustomerForm({ values, errors, groups, onChange }: Props) {
    return (
        <div className="row g-3">
            <div className="col-12 col-lg-6">
                <div className="card h-100">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Identity</h2></div>
                    <div className="card-body">
                        <FormField label="Name" name="name" error={errors.name} required>
                            <TextInput name="name" value={values.name} invalid={!!errors.name} onChange={(v) => onChange('name', v)} />
                        </FormField>

                        <FormField label="Type" name="type" error={errors.type} required>
                            <SelectInput
                                name="type"
                                value={values.type}
                                invalid={!!errors.type}
                                options={[
                                    { value: 'individual', label: 'Individual' },
                                    { value: 'business', label: 'Business' },
                                ]}
                                onChange={(v) => onChange('type', v)}
                            />
                        </FormField>

                        {values.type === 'business' ? (
                            <FormField label="Registered company name" name="company_name" error={errors.company_name}>
                                <TextInput name="company_name" value={values.company_name} invalid={!!errors.company_name} onChange={(v) => onChange('company_name', v)} />
                            </FormField>
                        ) : null}

                        <FormField label="Code" name="code" error={errors.code} hint="Leave blank to allocate the next sequential code.">
                            <TextInput name="code" value={values.code} invalid={!!errors.code} onChange={(v) => onChange('code', v)} />
                        </FormField>

                        <FormField label="Status" name="status" error={errors.status} required>
                            <SelectInput
                                name="status"
                                value={values.status}
                                invalid={!!errors.status}
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
                        <FormField label="Phone" name="phone" error={errors.phone}>
                            <TextInput name="phone" value={values.phone} invalid={!!errors.phone} onChange={(v) => onChange('phone', v)} />
                        </FormField>

                        <FormField label="Email" name="email" error={errors.email}>
                            <TextInput name="email" type="email" value={values.email} invalid={!!errors.email} onChange={(v) => onChange('email', v)} />
                        </FormField>

                        <FormField label="Tax number" name="tax_no" error={errors.tax_no}>
                            <TextInput name="tax_no" value={values.tax_no} invalid={!!errors.tax_no} onChange={(v) => onChange('tax_no', v)} />
                        </FormField>

                        <FormField label="Customer group" name="customer_group_id" error={errors.customer_group_id} hint="Groups can carry their own price list.">
                            <SelectInput
                                name="customer_group_id"
                                value={values.customer_group_id}
                                placeholder="No group"
                                invalid={!!errors.customer_group_id}
                                options={groups}
                                onChange={(v) => onChange('customer_group_id', v)}
                            />
                        </FormField>

                        <div className="row">
                            <div className="col-6">
                                <FormField label="Credit limit" name="credit_limit" error={errors.credit_limit} required>
                                    <TextInput name="credit_limit" value={values.credit_limit} invalid={!!errors.credit_limit} onChange={(v) => onChange('credit_limit', v)} />
                                </FormField>
                            </div>
                            <div className="col-6">
                                <FormField label="Payment terms (days)" name="payment_terms_days" error={errors.payment_terms_days} required>
                                    <TextInput name="payment_terms_days" value={values.payment_terms_days} invalid={!!errors.payment_terms_days} onChange={(v) => onChange('payment_terms_days', v)} />
                                </FormField>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="col-12">
                <div className="card">
                    <div className="card-body">
                        <FormField label="Notes" name="notes" error={errors.notes}>
                            <textarea
                                id="notes"
                                className={`form-control ${errors.notes ? 'is-invalid' : ''}`}
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
