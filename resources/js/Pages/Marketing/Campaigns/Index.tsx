import { Head, Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import Pagination from '@/Components/Pagination'
import { useAuth } from '@/Hooks/useAuth'
import type { Paginated } from '@/Types'

interface Option {
    value: string
    label: string
}

interface Row {
    id: string
    code: string | null
    name: string
    status: string
    channel: string | null
    marketer: string | null
    budget: string
    spend: string
    starts_at: string | null
    ends_at: string | null
}

interface Props {
    campaigns: Paginated<Row>
    filters: { status: string }
    channels: Option[]
    marketers: Option[]
    statuses: Option[]
    can: { manage: boolean }
}

export const campaignTone = (status: string) =>
    status === 'active' ? 'success' : status === 'ended' ? 'neutral' : status === 'paused' ? 'warning' : 'info'

export default function CampaignIndex({ campaigns, filters, channels, marketers, statuses, can }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'
    const [open, setOpen] = useState(false)

    const form = useForm({
        code: '',
        name: '',
        status: 'active',
        channel_id: '',
        marketer_id: '',
        budget: '0',
        starts_at: '',
        ends_at: '',
    })

    const columns: Column<Row>[] = [
        {
            key: 'campaign',
            header: 'Campaign',
            render: (row) => (
                <div>
                    <Link href={`/campaigns/${row.id}`} className="fw-semibold text-decoration-none">{row.name}</Link>
                    <div className="small text-body-secondary font-monospace">{row.code ?? '—'}</div>
                </div>
            ),
        },
        { key: 'channel', header: 'Channel', render: (row) => row.channel ?? '—' },
        { key: 'marketer', header: 'Marketer', render: (row) => row.marketer ?? '—' },
        { key: 'budget', header: 'Budget', align: 'end', render: (row) => <MoneyText amount={row.budget} currency={currency} muted /> },
        {
            key: 'spend',
            header: 'Spent',
            align: 'end',
            render: (row) => (
                <span className={Number(row.spend) > Number(row.budget) && Number(row.budget) > 0 ? 'text-danger fw-semibold' : ''}>
                    <MoneyText amount={row.spend} currency={currency} />
                </span>
            ),
        },
        { key: 'status', header: 'Status', render: (row) => <StatusBadge label={row.status} tone={campaignTone(row.status)} /> },
    ]

    return (
        <AppLayout>
            <Head title="Campaigns" />

            <PageHeader
                title="Campaigns"
                subtitle="What you spent to bring business in — and what the attribution report can then compare it against."
                actions={can.manage ? <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>New campaign</button> : null}
            />

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/campaigns')
                            }}
                        >
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="name">Name</label>
                                <input id="name" className={`form-control ${form.errors.name ? 'is-invalid' : ''}`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                {form.errors.name ? <div className="invalid-feedback d-block">{form.errors.name}</div> : null}
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="code">Code</label>
                                <input id="code" className={`form-control ${form.errors.code ? 'is-invalid' : ''}`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                                {form.errors.code ? <div className="invalid-feedback d-block">{form.errors.code}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="channel_id">Channel</label>
                                <select id="channel_id" className="form-select" value={form.data.channel_id} onChange={(e) => form.setData('channel_id', e.target.value)}>
                                    <option value="">None</option>
                                    {channels.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                                </select>
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="marketer_id">Marketer</label>
                                <select id="marketer_id" className="form-select" value={form.data.marketer_id} onChange={(e) => form.setData('marketer_id', e.target.value)}>
                                    <option value="">None</option>
                                    {marketers.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                                </select>
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="budget">Budget</label>
                                <input id="budget" className="form-control text-end font-monospace" inputMode="decimal" value={form.data.budget} onChange={(e) => form.setData('budget', e.target.value)} />
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="starts_at">Starts</label>
                                <input id="starts_at" type="date" className="form-control" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="ends_at">Ends</label>
                                <input id="ends_at" type="date" className={`form-control ${form.errors.ends_at ? 'is-invalid' : ''}`} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                                {form.errors.ends_at ? <div className="invalid-feedback d-block">{form.errors.ends_at}</div> : null}
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="status">Status</label>
                                <select id="status" className="form-select" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                    {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                                </select>
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>Create</button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-header bg-body d-flex justify-content-end">
                    <select
                        className="form-select form-select-sm"
                        style={{ maxWidth: '12rem' }}
                        value={filters.status}
                        aria-label="Filter by status"
                        onChange={(e) => router.get('/campaigns', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true, replace: true })}
                    >
                        <option value="">Any status</option>
                        {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                </div>
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={campaigns.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No campaigns"
                        emptyDescription="A campaign is what ad spend attaches to, and what revenue gets compared against."
                    />
                </div>
                <div className="card-footer bg-body"><Pagination meta={campaigns} /></div>
            </div>
        </AppLayout>
    )
}
