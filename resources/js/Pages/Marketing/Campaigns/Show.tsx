import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import { useAuth } from '@/Hooks/useAuth'
import { campaignTone } from './Index'

interface Option {
    value: string
    label: string
}

interface Cost {
    id: string
    period: string | null
    platform: string | null
    amount: string
    spent_on: string | null
    note: string | null
    recorded_by: string
}

interface Props {
    campaign: {
        id: string
        code: string | null
        name: string
        status: string
        channel_id: string | null
        channel: string | null
        marketer_id: string | null
        marketer: string | null
        budget: string
        spend: string
        remaining: string
        starts_at: string | null
        ends_at: string | null
    }
    costs: Cost[]
    channels: Option[]
    marketers: Option[]
    statuses: Option[]
    can: { manage: boolean }
}

export default function CampaignShow({ campaign, costs, channels, marketers, statuses, can }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const settings = useForm({
        code: campaign.code ?? '',
        name: campaign.name,
        status: campaign.status,
        channel_id: campaign.channel_id ?? '',
        marketer_id: campaign.marketer_id ?? '',
        budget: campaign.budget,
        starts_at: campaign.starts_at ?? '',
        ends_at: campaign.ends_at ?? '',
    })

    const spend = useForm({ platform: '', amount: '', spent_on: '', note: '' })

    const columns: Column<Cost>[] = [
        { key: 'spent_on', header: 'Date', render: (row) => row.spent_on ?? '—' },
        { key: 'platform', header: 'Platform', render: (row) => row.platform ?? '—' },
        { key: 'amount', header: 'Amount', align: 'end', render: (row) => <MoneyText amount={row.amount} currency={currency} /> },
        { key: 'note', header: 'Note', render: (row) => row.note ?? '—' },
        { key: 'by', header: 'By', render: (row) => row.recorded_by },
    ]

    const overBudget = Number(campaign.budget) > 0 && Number(campaign.spend) > Number(campaign.budget)

    return (
        <AppLayout>
            <Head title={campaign.name} />

            <PageHeader
                title={campaign.name}
                subtitle={`${campaign.code ?? '—'}${campaign.channel ? ` · ${campaign.channel}` : ''}${campaign.marketer ? ` · ${campaign.marketer}` : ''}`}
                actions={<Link href="/campaigns" className="btn btn-sm btn-outline-secondary">Back</Link>}
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={campaign.status} tone={campaignTone(campaign.status)} />
                {campaign.starts_at ? <StatusBadge label={`From ${campaign.starts_at}`} tone="info" /> : null}
                {campaign.ends_at ? <StatusBadge label={`To ${campaign.ends_at}`} tone="info" /> : null}
            </div>

            <div className="row g-3 mb-4">
                <div className="col-6 col-lg-4"><StatCard label="Budget" value={`${currency} ${Number(campaign.budget).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`} /></div>
                <div className="col-6 col-lg-4"><StatCard label="Spent" value={`${currency} ${Number(campaign.spend).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`} tone={overBudget ? 'danger' : 'default'} /></div>
                <div className="col-6 col-lg-4">
                    <StatCard
                        label="Remaining"
                        value={`${currency} ${Number(campaign.remaining).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                        tone={Number(campaign.remaining) < 0 ? 'danger' : 'success'}
                        hint={overBudget ? 'Over budget — nothing stops this, it is only reported' : undefined}
                    />
                </div>
            </div>

            <div className="row g-3">
                <div className="col-12 col-lg-4">
                    {can.manage ? (
                        <div className="card mb-3">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Record ad spend</h2></div>
                            <div className="card-body">
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault()
                                        spend.post(`/campaigns/${campaign.id}/costs`, { preserveScroll: true, onSuccess: () => spend.reset() })
                                    }}
                                >
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="platform">Platform</label>
                                        <input id="platform" className={`form-control ${spend.errors.platform ? 'is-invalid' : ''}`} placeholder="Meta, Google, TikTok…" value={spend.data.platform} onChange={(e) => spend.setData('platform', e.target.value)} />
                                        {spend.errors.platform ? <div className="invalid-feedback d-block">{spend.errors.platform}</div> : null}
                                    </div>
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="amount">Amount</label>
                                        <input id="amount" className={`form-control text-end font-monospace ${spend.errors.amount ? 'is-invalid' : ''}`} inputMode="decimal" value={spend.data.amount} onChange={(e) => spend.setData('amount', e.target.value)} />
                                        {spend.errors.amount ? <div className="invalid-feedback d-block">{spend.errors.amount}</div> : null}
                                    </div>
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="spent_on">Spent on</label>
                                        <input id="spent_on" type="date" className={`form-control ${spend.errors.spent_on ? 'is-invalid' : ''}`} value={spend.data.spent_on} onChange={(e) => spend.setData('spent_on', e.target.value)} />
                                        {spend.errors.spent_on ? <div className="invalid-feedback d-block">{spend.errors.spent_on}</div> : null}
                                    </div>
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="note">Note</label>
                                        <input id="note" className="form-control" value={spend.data.note} onChange={(e) => spend.setData('note', e.target.value)} />
                                    </div>
                                    <button type="submit" className="btn btn-primary w-100" disabled={spend.processing}>
                                        {spend.processing ? 'Recording…' : 'Record spend'}
                                    </button>
                                    <p className="form-text mb-0">
                                        A margin-based commission plan nets this off before it calculates, so a marketer is
                                        paid on what the campaign actually made.
                                    </p>
                                </form>
                            </div>
                        </div>
                    ) : null}

                    {can.manage ? (
                        <div className="card">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Settings</h2></div>
                            <div className="card-body">
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault()
                                        settings.put(`/campaigns/${campaign.id}`, { preserveScroll: true })
                                    }}
                                >
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="s_name">Name</label>
                                        <input id="s_name" className="form-control" value={settings.data.name} onChange={(e) => settings.setData('name', e.target.value)} />
                                    </div>
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="s_status">Status</label>
                                        <select id="s_status" className="form-select" value={settings.data.status} onChange={(e) => settings.setData('status', e.target.value)}>
                                            {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                                        </select>
                                    </div>
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="s_channel">Channel</label>
                                        <select id="s_channel" className="form-select" value={settings.data.channel_id} onChange={(e) => settings.setData('channel_id', e.target.value)}>
                                            <option value="">None</option>
                                            {channels.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                                        </select>
                                    </div>
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="s_marketer">Marketer</label>
                                        <select id="s_marketer" className="form-select" value={settings.data.marketer_id} onChange={(e) => settings.setData('marketer_id', e.target.value)}>
                                            <option value="">None</option>
                                            {marketers.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                                        </select>
                                    </div>
                                    <div className="mb-3">
                                        <label className="form-label" htmlFor="s_budget">Budget</label>
                                        <input id="s_budget" className="form-control text-end font-monospace" inputMode="decimal" value={settings.data.budget} onChange={(e) => settings.setData('budget', e.target.value)} />
                                    </div>
                                    <button type="submit" className="btn btn-outline-primary w-100" disabled={settings.processing}>Save settings</button>
                                </form>
                            </div>
                        </div>
                    ) : null}
                </div>

                <div className="col-12 col-lg-8">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Ad spend</h2></div>
                        <div className="card-body p-0">
                            <DataTable
                                columns={columns}
                                rows={costs}
                                rowKey={(row) => row.id}
                                emptyTitle="No spend recorded"
                                emptyDescription="Until spend is entered here, a margin plan treats this campaign as free — and overpays."
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
