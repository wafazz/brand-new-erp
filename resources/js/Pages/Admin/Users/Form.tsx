import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'
import SelectInput from '@/Components/SelectInput'

export interface RoleOption {
    value: string
    label: string
    grantable: boolean
    reason: string | null
}

export interface Reference {
    value: string
    label: string
}

export interface MemberFormValues {
    name: string
    email: string
    password: string
    role: string
    branch_id: string
    employee_no: string
    is_active: boolean
}

export const EMPTY_MEMBER: MemberFormValues = {
    name: '',
    email: '',
    password: '',
    role: '',
    branch_id: '',
    employee_no: '',
    is_active: true,
}

interface Props {
    values: MemberFormValues
    errors: Partial<Record<string, string>>
    roles: RoleOption[]
    branches: Reference[]
    withAccount: boolean
    onChange: <K extends keyof MemberFormValues>(key: K, value: MemberFormValues[K]) => void
}

export default function MemberForm({ values, errors, roles, branches, withAccount, onChange }: Props) {
    const chosen = roles.find((role) => role.value === values.role)

    return (
        <div className="row g-3">
            {withAccount ? (
                <div className="col-12 col-lg-6">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Account</h2></div>
                        <div className="card-body">
                            <FormField label="Name" name="name" required error={errors.name}>
                                <TextInput name="name" value={values.name} invalid={Boolean(errors.name)} onChange={(v) => onChange('name', v)} />
                            </FormField>

                            <FormField label="Email" name="email" required error={errors.email}>
                                <TextInput name="email" type="email" value={values.email} invalid={Boolean(errors.email)} onChange={(v) => onChange('email', v)} />
                            </FormField>

                            <FormField
                                label="Initial password"
                                name="password"
                                required
                                error={errors.password}
                                hint="At least 12 characters. Hand it over in person and have them change it from their profile — nothing forces a change yet."
                            >
                                <TextInput name="password" type="password" value={values.password} invalid={Boolean(errors.password)} onChange={(v) => onChange('password', v)} />
                            </FormField>
                        </div>
                    </div>
                </div>
            ) : null}

            <div className={withAccount ? 'col-12 col-lg-6' : 'col-12 col-lg-8'}>
                <div className="card h-100">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">Access</h2></div>
                    <div className="card-body">
                        <FormField label="Role" name="role" required error={errors.role}>
                            <SelectInput
                                name="role"
                                value={values.role}
                                placeholder="Choose a role…"
                                invalid={Boolean(errors.role)}
                                options={roles.filter((role) => role.grantable).map((role) => ({ value: role.value, label: role.label }))}
                                onChange={(v) => onChange('role', v)}
                            />
                        </FormField>

                        {chosen && !chosen.grantable ? (
                            <div className="alert alert-warning py-2 small">{chosen.reason}</div>
                        ) : null}

                        <div className="alert alert-secondary py-2 small mb-3">
                            Only roles you could hand over are listed. A role carrying anything you do not hold yourself
                            is hidden here, and refused by the server if it is sent anyway.
                        </div>

                        <FormField label="Branch" name="branch_id" error={errors.branch_id} hint="Branch-scoped roles see only this branch.">
                            <SelectInput name="branch_id" value={values.branch_id} options={branches} placeholder="No branch" onChange={(v) => onChange('branch_id', v)} />
                        </FormField>

                        <FormField label="Employee number" name="employee_no" error={errors.employee_no}>
                            <TextInput name="employee_no" value={values.employee_no} onChange={(v) => onChange('employee_no', v)} />
                        </FormField>

                        <div className="form-check">
                            <input
                                id="is_active"
                                className="form-check-input"
                                type="checkbox"
                                checked={values.is_active}
                                onChange={(e) => onChange('is_active', e.target.checked)}
                            />
                            <label className="form-check-label" htmlFor="is_active">Active</label>
                            <div className="form-text">An inactive member keeps their history but cannot act.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
