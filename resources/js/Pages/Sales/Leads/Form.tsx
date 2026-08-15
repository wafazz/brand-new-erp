import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'
import SelectInput from '@/Components/SelectInput'

export interface LeadFormValues {
    reference: string
    name: string
    phone: string
    email: string
    status: string
    pipeline_stage_id: string
    assigned_to: string
    branch_id: string
    estimated_value: string
    note: string
}

export const EMPTY_LEAD: LeadFormValues = {
    reference: '',
    name: '',
    phone: '',
    email: '',
    status: 'new',
    pipeline_stage_id: '',
    assigned_to: '',
    branch_id: '',
    estimated_value: '0',
    note: '',
}

export interface Reference {
    value: string
    label: string
}

interface Props {
    values: LeadFormValues
    errors: Partial<Record<string, string>>
    stages: Reference[]
    branches: Reference[]
    assignees: Reference[]
    statuses: Reference[]
    onChange: <K extends keyof LeadFormValues>(key: K, value: LeadFormValues[K]) => void
}

export default function LeadForm({ values, errors, stages, branches, assignees, statuses, onChange }: Props) {
    return (
        <div className="row g-3">
            <div className="col-12 col-lg-7">
                <div className="card h-100">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Who</h2></div>
                    <div className="card-body">
                        <FormField label="Name" name="name" required error={errors.name}>
                            <TextInput name="name" value={values.name} invalid={Boolean(errors.name)} onChange={(v) => onChange('name', v)} />
                        </FormField>

                        <div className="row">
                            <div className="col-md-6">
                                <FormField label="Phone" name="phone" error={errors.phone}>
                                    <TextInput name="phone" value={values.phone} onChange={(v) => onChange('phone', v)} />
                                </FormField>
                            </div>
                            <div className="col-md-6">
                                <FormField label="Email" name="email" error={errors.email}>
                                    <TextInput name="email" type="email" value={values.email} invalid={Boolean(errors.email)} onChange={(v) => onChange('email', v)} />
                                </FormField>
                            </div>
                        </div>

                        <FormField label="Note" name="note" error={errors.note}>
                            <textarea
                                id="note"
                                name="note"
                                className="form-control"
                                rows={3}
                                value={values.note}
                                onChange={(e) => onChange('note', e.target.value)}
                            />
                        </FormField>
                    </div>
                </div>
            </div>

            <div className="col-12 col-lg-5">
                <div className="card h-100">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Pipeline</h2></div>
                    <div className="card-body">
                        <FormField label="Status" name="status" required error={errors.status}>
                            <SelectInput name="status" value={values.status} options={statuses} invalid={Boolean(errors.status)} onChange={(v) => onChange('status', v)} />
                        </FormField>

                        <FormField label="Stage" name="pipeline_stage_id" error={errors.pipeline_stage_id}>
                            <SelectInput name="pipeline_stage_id" value={values.pipeline_stage_id} options={stages} placeholder="No stage" onChange={(v) => onChange('pipeline_stage_id', v)} />
                        </FormField>

                        <FormField label="Assigned to" name="assigned_to" error={errors.assigned_to} hint="Defaults to you.">
                            <SelectInput name="assigned_to" value={values.assigned_to} options={assignees} placeholder="Me" onChange={(v) => onChange('assigned_to', v)} />
                        </FormField>

                        <FormField label="Branch" name="branch_id" error={errors.branch_id}>
                            <SelectInput name="branch_id" value={values.branch_id} options={branches} placeholder="No branch" onChange={(v) => onChange('branch_id', v)} />
                        </FormField>

                        <FormField label="Estimated value" name="estimated_value" required error={errors.estimated_value}>
                            <TextInput name="estimated_value" value={values.estimated_value} invalid={Boolean(errors.estimated_value)} onChange={(v) => onChange('estimated_value', v)} />
                        </FormField>
                    </div>
                </div>
            </div>
        </div>
    )
}
