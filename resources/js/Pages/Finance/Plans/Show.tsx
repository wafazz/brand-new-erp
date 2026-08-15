import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatusBadge from '@/Components/StatusBadge'
import EmptyState from '@/Components/EmptyState'
import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'
import SelectInput from '@/Components/SelectInput'
import type { PlanOptions } from './Index'

interface Version {
    id: string
    version: number
    rate_type: string
    rate_value: string
    valid_from: string | null
    valid_to: string | null
    state: string
}

interface Rule {
    id: string
    code: string | null
    name: string
    is_active: boolean
    versions: Version[]
}

interface Props {
    plan: {
        id: string
        code: string | null
        name: string
        strategy: string
        recipient_role: string
        ad_spend_allocation: string
        is_active: boolean
        has_accruals: boolean
        expected_rate_type: string
    }
    rules: Rule[]
    options: PlanOptions
}

export default function PlanShow({ plan, rules, options }: Props) {
    const [ruleOpen, setRuleOpen] = useState(false)
    const [rateFor, setRateFor] = useState<string | null>(null)

    const settings = useForm({
        code: plan.code ?? '',
        name: plan.name,
        strategy: plan.strategy,
        recipient_role: plan.recipient_role,
        ad_spend_allocation: plan.ad_spend_allocation,
        is_active: plan.is_active,
    })

    const newRule = useForm({ code: '', name: '' })
    const newRate = useForm({ rate_type: plan.expected_rate_type, rate_value: '', valid_from: '' })
    const toggleRule = useForm({ is_active: false })

    return (
        <AppLayout>
            <Head title={plan.name} />

            <PageHeader
                title={plan.name}
                subtitle={`${plan.code ?? '—'} · pays ${plan.recipient_role.replace(/_/g, ' ')} on ${plan.strategy.replace(/_/g, ' ')}`}
                actions={<Link href="/commission-plans" className="btn btn-sm btn-outline-secondary">Back</Link>}
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={plan.is_active ? 'active' : 'stopped'} tone={plan.is_active ? 'success' : 'neutral'} />
                {plan.has_accruals ? <StatusBadge label="has paid out" tone="info" /> : null}
            </div>

            <div className="row g-3">
                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Settings</h2></div>
                        <div className="card-body">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault()
                                    settings.put(`/commission-plans/${plan.id}`, { preserveScroll: true })
                                }}
                            >
                                <FormField label="Name" name="name" required error={settings.errors.name}>
                                    <TextInput name="name" value={settings.data.name} onChange={(v) => settings.setData('name', v)} />
                                </FormField>

                                <FormField
                                    label="Pays on"
                                    name="strategy"
                                    required
                                    error={settings.errors.strategy}
                                    hint={plan.has_accruals ? 'Locked — this plan has already paid out.' : undefined}
                                >
                                    <SelectInput
                                        name="strategy"
                                        value={settings.data.strategy}
                                        options={plan.has_accruals ? options.strategies.filter((s) => s.value === plan.strategy) : options.strategies}
                                        onChange={(v) => settings.setData('strategy', v)}
                                    />
                                </FormField>

                                <FormField
                                    label="Pays to"
                                    name="recipient_role"
                                    required
                                    error={settings.errors.recipient_role}
                                    hint={plan.has_accruals ? 'Locked — this plan has already paid out.' : undefined}
                                >
                                    <SelectInput
                                        name="recipient_role"
                                        value={settings.data.recipient_role}
                                        options={plan.has_accruals ? options.recipients.filter((r) => r.value === plan.recipient_role) : options.recipients}
                                        onChange={(v) => settings.setData('recipient_role', v)}
                                    />
                                </FormField>

                                <FormField label="Ad spend" name="ad_spend_allocation" required error={settings.errors.ad_spend_allocation}>
                                    <SelectInput name="ad_spend_allocation" value={settings.data.ad_spend_allocation} options={options.allocations} onChange={(v) => settings.setData('ad_spend_allocation', v)} />
                                </FormField>

                                <div className="form-check mb-3">
                                    <input
                                        id="is_active"
                                        className="form-check-input"
                                        type="checkbox"
                                        checked={settings.data.is_active}
                                        onChange={(e) => settings.setData('is_active', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="is_active">Active</label>
                                    <div className="form-text">Stopping a plan halts new accruals. Nothing already accrued is touched.</div>
                                </div>

                                <button type="submit" className="btn btn-primary w-100" disabled={settings.processing}>
                                    {settings.processing ? 'Saving…' : 'Save settings'}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-8">
                    <div className="card">
                        <div className="card-header bg-body d-flex align-items-center justify-content-between">
                            <h2 className="h6 mb-0">Rules</h2>
                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setRuleOpen((o) => !o)}>Add rule</button>
                        </div>
                        <div className="card-body">
                            {ruleOpen ? (
                                <form
                                    className="row g-2 align-items-end mb-4"
                                    onSubmit={(event) => {
                                        event.preventDefault()
                                        newRule.post(`/commission-plans/${plan.id}/rules`, {
                                            preserveScroll: true,
                                            onSuccess: () => { newRule.reset(); setRuleOpen(false) },
                                        })
                                    }}
                                >
                                    <div className="col-12 col-md-5">
                                        <label className="form-label" htmlFor="rule_name">Rule name</label>
                                        <input id="rule_name" className={`form-control ${newRule.errors.name ? 'is-invalid' : ''}`} value={newRule.data.name} onChange={(e) => newRule.setData('name', e.target.value)} />
                                        {newRule.errors.name ? <div className="invalid-feedback d-block">{newRule.errors.name}</div> : null}
                                    </div>
                                    <div className="col-12 col-md-4">
                                        <label className="form-label" htmlFor="rule_code">Code</label>
                                        <input id="rule_code" className={`form-control ${newRule.errors.code ? 'is-invalid' : ''}`} value={newRule.data.code} onChange={(e) => newRule.setData('code', e.target.value)} />
                                        {newRule.errors.code ? <div className="invalid-feedback d-block">{newRule.errors.code}</div> : null}
                                    </div>
                                    <div className="col-12 col-md-3 d-grid">
                                        <button type="submit" className="btn btn-primary" disabled={newRule.processing}>Add rule</button>
                                    </div>
                                </form>
                            ) : null}

                            {rules.length === 0 ? (
                                <EmptyState
                                    title="No rules yet"
                                    description="A plan with no live rule accrues nothing. Add a rule, then publish a rate for it."
                                />
                            ) : (
                                <div className="d-flex flex-column gap-3">
                                    {rules.map((rule) => {
                                        const current = rule.versions.find((v) => v.state === 'in force')

                                        return (
                                            <div key={rule.id} className="border rounded p-3">
                                                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                    <div>
                                                        <div className="fw-semibold">
                                                            {rule.name}
                                                            {rule.is_active ? null : <span className="ms-2"><StatusBadge label="stopped" tone="neutral" /></span>}
                                                        </div>
                                                        <div className="small text-body-secondary font-monospace">{rule.code ?? '—'}</div>
                                                    </div>
                                                    <div className="d-flex gap-2">
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-secondary"
                                                            onClick={() => {
                                                                toggleRule.setData('is_active', !rule.is_active)
                                                                toggleRule.transform(() => ({ is_active: !rule.is_active }))
                                                                toggleRule.put(`/commission-rules/${rule.id}`, { preserveScroll: true })
                                                            }}
                                                        >
                                                            {rule.is_active ? 'Stop' : 'Resume'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-primary"
                                                            onClick={() => setRateFor((current2) => (current2 === rule.id ? null : rule.id))}
                                                        >
                                                            Publish rate
                                                        </button>
                                                    </div>
                                                </div>

                                                <div className="mt-2">
                                                    {current === undefined ? (
                                                        <span className="small text-warning">No rate published — this rule pays nothing.</span>
                                                    ) : (
                                                        <span className="small">
                                                            Currently <span className="font-monospace fw-semibold">
                                                                {current.rate_type === 'percent' ? `${Number(current.rate_value).toFixed(2)}%` : Number(current.rate_value).toFixed(2)}
                                                            </span> since {current.valid_from}
                                                        </span>
                                                    )}
                                                </div>

                                                {rateFor === rule.id ? (
                                                    <form
                                                        className="row g-2 align-items-end mt-2"
                                                        onSubmit={(event) => {
                                                            event.preventDefault()
                                                            newRate.post(`/commission-rules/${rule.id}/versions`, {
                                                                preserveScroll: true,
                                                                onSuccess: () => { newRate.reset(); setRateFor(null) },
                                                            })
                                                        }}
                                                    >
                                                        <div className="col-6 col-md-3">
                                                            <label className="form-label small" htmlFor={`rate_type-${rule.id}`}>Type</label>
                                                            <select
                                                                id={`rate_type-${rule.id}`}
                                                                className="form-select form-select-sm"
                                                                value={newRate.data.rate_type}
                                                                onChange={(e) => newRate.setData('rate_type', e.target.value)}
                                                            >
                                                                {options.rateTypes.map((type) => (
                                                                    <option key={type.value} value={type.value}>{type.label}</option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                        <div className="col-6 col-md-3">
                                                            <label className="form-label small" htmlFor={`rate_value-${rule.id}`}>Rate</label>
                                                            <input
                                                                id={`rate_value-${rule.id}`}
                                                                className={`form-control form-control-sm text-end font-monospace ${newRate.errors.rate_value ? 'is-invalid' : ''}`}
                                                                inputMode="decimal"
                                                                value={newRate.data.rate_value}
                                                                onChange={(e) => newRate.setData('rate_value', e.target.value)}
                                                            />
                                                        </div>
                                                        <div className="col-12 col-md-4">
                                                            <label className="form-label small" htmlFor={`valid_from-${rule.id}`}>Effective from</label>
                                                            <input
                                                                id={`valid_from-${rule.id}`}
                                                                type="date"
                                                                className={`form-control form-control-sm ${newRate.errors.valid_from ? 'is-invalid' : ''}`}
                                                                value={newRate.data.valid_from}
                                                                onChange={(e) => newRate.setData('valid_from', e.target.value)}
                                                            />
                                                        </div>
                                                        <div className="col-12 col-md-2 d-grid">
                                                            <button type="submit" className="btn btn-sm btn-primary" disabled={newRate.processing}>Publish</button>
                                                        </div>
                                                        <div className="col-12">
                                                            <p className="form-text mb-0">
                                                                This publishes a <strong>new version</strong>. Earlier versions are never touched — the database
                                                                refuses to change one — so commission already accrued keeps explaining itself. Which rate applies
                                                                is decided by its effective date.
                                                            </p>
                                                        </div>
                                                    </form>
                                                ) : null}

                                                {rule.versions.length > 0 ? (
                                                    <div className="table-responsive mt-3">
                                                        <table className="table table-sm small mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th scope="col">Version</th>
                                                                    <th scope="col" className="text-end">Rate</th>
                                                                    <th scope="col">From</th>
                                                                    <th scope="col">State</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                {rule.versions.map((version) => (
                                                                    <tr key={version.id} className={version.state === 'in force' ? 'table-active' : ''}>
                                                                        <td>v{version.version}</td>
                                                                        <td className="text-end font-monospace">
                                                                            {version.rate_type === 'percent' ? `${Number(version.rate_value).toFixed(2)}%` : Number(version.rate_value).toFixed(2)}
                                                                        </td>
                                                                        <td>{version.valid_from ?? '—'}</td>
                                                                        <td>{version.state === 'in force' ? <span className="text-success">in force</span> : version.state}</td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                ) : null}
                                            </div>
                                        )
                                    })}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
