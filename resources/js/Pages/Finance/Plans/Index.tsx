import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'
import SelectInput from '@/Components/SelectInput'

interface Option {
    value: string
    label: string
}

export interface PlanOptions {
    strategies: Option[]
    recipients: Option[]
    allocations: Option[]
    rateTypes: Option[]
}

interface Row {
    id: string
    code: string | null
    name: string
    strategy: string
    recipient_role: string
    ad_spend_allocation: string
    is_active: boolean
    rules_count: number
    accruals: number
}

interface Props {
    plans: Row[]
    options: PlanOptions
}

export default function PlanIndex({ plans, options }: Props) {
    const [open, setOpen] = useState(false)

    const form = useForm({
        code: '',
        name: '',
        strategy: options.strategies[0]?.value ?? '',
        recipient_role: options.recipients[0]?.value ?? '',
        ad_spend_allocation: options.allocations[0]?.value ?? '',
        is_active: true,
    })

    const columns: Column<Row>[] = [
        {
            key: 'plan',
            header: 'Plan',
            render: (row) => (
                <div>
                    <Link href={`/commission-plans/${row.id}`} className="fw-semibold text-decoration-none">{row.name}</Link>
                    <div className="small text-body-secondary font-monospace">{row.code ?? '—'}</div>
                </div>
            ),
        },
        { key: 'strategy', header: 'Pays on', render: (row) => row.strategy.replace(/_/g, ' ') },
        { key: 'recipient', header: 'Pays to', render: (row) => row.recipient_role.replace(/_/g, ' ') },
        { key: 'rules', header: 'Live rules', align: 'end', render: (row) => String(row.rules_count) },
        { key: 'accruals', header: 'Accrued', align: 'end', render: (row) => String(row.accruals) },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.is_active ? 'active' : 'stopped'} tone={row.is_active ? 'success' : 'neutral'} />,
        },
    ]

    return (
        <AppLayout>
            <Head title="Commission plans" />

            <PageHeader
                title="Commission plans"
                subtitle="A plan says who gets paid and on what. A rule under it says how much, and every rate change is a new version."
                actions={
                    <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>New plan</button>
                }
            />

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-header bg-body"><h2 className="h6 mb-0">New plan</h2></div>
                    <div className="card-body">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/commission-plans', { onSuccess: () => setOpen(false) })
                            }}
                        >
                            <div className="row">
                                <div className="col-md-4">
                                    <FormField label="Name" name="name" required error={form.errors.name}>
                                        <TextInput name="name" value={form.data.name} invalid={Boolean(form.errors.name)} onChange={(v) => form.setData('name', v)} />
                                    </FormField>
                                </div>
                                <div className="col-md-2">
                                    <FormField label="Code" name="code" required error={form.errors.code}>
                                        <TextInput name="code" value={form.data.code} invalid={Boolean(form.errors.code)} onChange={(v) => form.setData('code', v)} />
                                    </FormField>
                                </div>
                                <div className="col-md-3">
                                    <FormField label="Pays on" name="strategy" required error={form.errors.strategy}>
                                        <SelectInput name="strategy" value={form.data.strategy} options={options.strategies} onChange={(v) => form.setData('strategy', v)} />
                                    </FormField>
                                </div>
                                <div className="col-md-3">
                                    <FormField label="Pays to" name="recipient_role" required error={form.errors.recipient_role}>
                                        <SelectInput name="recipient_role" value={form.data.recipient_role} options={options.recipients} onChange={(v) => form.setData('recipient_role', v)} />
                                    </FormField>
                                </div>
                            </div>

                            <FormField
                                label="Ad spend"
                                name="ad_spend_allocation"
                                required
                                error={form.errors.ad_spend_allocation}
                                hint="How campaign spend is deducted before a margin-based commission is calculated."
                            >
                                <SelectInput name="ad_spend_allocation" value={form.data.ad_spend_allocation} options={options.allocations} onChange={(v) => form.setData('ad_spend_allocation', v)} />
                            </FormField>

                            <button type="submit" className="btn btn-primary" disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Create plan'}
                            </button>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={plans}
                        rowKey={(row) => row.id}
                        emptyTitle="No commission plans"
                        emptyDescription="Until a plan exists with a live rule, orders accrue no commission at all."
                    />
                </div>
            </div>
        </AppLayout>
    )
}
