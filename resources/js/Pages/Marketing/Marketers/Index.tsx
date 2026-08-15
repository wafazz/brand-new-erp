import { Head, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import EmptyState from '@/Components/EmptyState'

interface Row {
    id: string
    code: string | null
    name: string
    email: string | null
    team: string | null
    status: string
    campaigns: number
}

interface Option {
    value: string
    label: string
}

interface Props {
    marketers: Row[]
    candidates: Option[]
    teams: Option[]
    statuses: Option[]
    can: { manage: boolean }
}

export default function MarketerIndex({ marketers, candidates, teams, can }: Props) {
    const [open, setOpen] = useState(false)
    const form = useForm({ user_id: '', code: '', marketing_team_id: '' })
    const toggle = useForm({ status: '', marketing_team_id: '' })

    const columns: Column<Row>[] = [
        {
            key: 'marketer',
            header: 'Marketer',
            render: (row) => (
                <div>
                    <span className="fw-semibold">{row.name}</span>
                    <div className="small text-body-secondary font-monospace">{row.code ?? '—'}</div>
                </div>
            ),
        },
        { key: 'email', header: 'Email', render: (row) => row.email ?? '—' },
        { key: 'team', header: 'Team', render: (row) => row.team ?? '—' },
        { key: 'campaigns', header: 'Campaigns', align: 'end', render: (row) => String(row.campaigns) },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.status} tone={row.status === 'active' ? 'success' : 'neutral'} />,
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            render: (row) =>
                can.manage ? (
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary"
                        onClick={() => {
                            toggle.transform(() => ({ status: row.status === 'active' ? 'inactive' : 'active', marketing_team_id: '' }))
                            toggle.put(`/marketers/${row.id}`, { preserveScroll: true })
                        }}
                    >
                        {row.status === 'active' ? 'Deactivate' : 'Activate'}
                    </button>
                ) : null,
        },
    ]

    return (
        <AppLayout>
            <Head title="Marketers" />

            <PageHeader
                title="Marketers"
                subtitle="A marketer is a person who can be credited for a campaign, a referral code or a lead."
                actions={can.manage && candidates.length > 0 ? <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>Add marketer</button> : null}
            />

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/marketers', { onSuccess: () => { form.reset(); setOpen(false) } })
                            }}
                        >
                            <div className="col-12 col-md-5">
                                <label className="form-label" htmlFor="user_id">Person</label>
                                <select id="user_id" className={`form-select ${form.errors.user_id ? 'is-invalid' : ''}`} value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)}>
                                    <option value="">Choose someone…</option>
                                    {candidates.map((c) => (
                                        <option key={c.value} value={c.value}>{c.label}</option>
                                    ))}
                                </select>
                                {form.errors.user_id ? <div className="invalid-feedback d-block">{form.errors.user_id}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="code">Code</label>
                                <input id="code" className={`form-control ${form.errors.code ? 'is-invalid' : ''}`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                                {form.errors.code ? <div className="invalid-feedback d-block">{form.errors.code}</div> : null}
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="team">Team</label>
                                <select id="team" className="form-select" value={form.data.marketing_team_id} onChange={(e) => form.setData('marketing_team_id', e.target.value)}>
                                    <option value="">None</option>
                                    {teams.map((t) => (
                                        <option key={t.value} value={t.value}>{t.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>Add</button>
                            </div>
                        </form>
                        <p className="form-text mb-0">Only people who are already members of this company can become marketers.</p>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-body p-0">
                    {marketers.length === 0 && candidates.length === 0 ? (
                        <div className="p-3">
                            <EmptyState title="Nobody to add yet" description="Add people under Administration → People first, then link them here." />
                        </div>
                    ) : (
                        <DataTable
                            columns={columns}
                            rows={marketers}
                            rowKey={(row) => row.id}
                            emptyTitle="No marketers yet"
                            emptyDescription="A plan that pays marketers accrues nothing until someone is linked here."
                        />
                    )}
                </div>
            </div>
        </AppLayout>
    )
}
