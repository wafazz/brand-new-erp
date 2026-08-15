import { Head, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'

interface Row {
    id: string
    code: string | null
    name: string
    kind: string
    is_active: boolean
    campaigns: number
    attributions: number
}

interface Props {
    channels: Row[]
    kinds: { value: string; label: string }[]
    can: { manage: boolean }
}

export default function ChannelIndex({ channels, kinds, can }: Props) {
    const [open, setOpen] = useState(false)
    const form = useForm({ code: '', name: '', kind: kinds[0]?.value ?? 'marketing', is_active: true })
    const toggle = useForm({ code: '', name: '', kind: '', is_active: false })

    const columns: Column<Row>[] = [
        {
            key: 'channel',
            header: 'Channel',
            render: (row) => (
                <div>
                    <span className="fw-semibold">{row.name}</span>
                    <div className="small text-body-secondary font-monospace">{row.code ?? '—'}</div>
                </div>
            ),
        },
        { key: 'kind', header: 'Kind', render: (row) => row.kind },
        { key: 'campaigns', header: 'Campaigns', align: 'end', render: (row) => String(row.campaigns) },
        { key: 'attributions', header: 'Attributed', align: 'end', render: (row) => String(row.attributions) },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.is_active ? 'active' : 'off'} tone={row.is_active ? 'success' : 'neutral'} />,
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
                            toggle.transform(() => ({ code: row.code ?? '', name: row.name, kind: row.kind, is_active: !row.is_active }))
                            toggle.put(`/channels/${row.id}`, { preserveScroll: true })
                        }}
                    >
                        {row.is_active ? 'Turn off' : 'Turn on'}
                    </button>
                ) : null,
        },
    ]

    return (
        <AppLayout>
            <Head title="Channels" />

            <PageHeader
                title="Channels"
                subtitle="Where business arrives from. Every attribution and campaign hangs off one of these."
                actions={can.manage ? <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>New channel</button> : null}
            />

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/channels', { onSuccess: () => { form.reset(); setOpen(false) } })
                            }}
                        >
                            <div className="col-12 col-md-4">
                                <label className="form-label" htmlFor="name">Name</label>
                                <input id="name" className={`form-control ${form.errors.name ? 'is-invalid' : ''}`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                {form.errors.name ? <div className="invalid-feedback d-block">{form.errors.name}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="code">Code</label>
                                <input id="code" className={`form-control ${form.errors.code ? 'is-invalid' : ''}`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                                {form.errors.code ? <div className="invalid-feedback d-block">{form.errors.code}</div> : null}
                            </div>
                            <div className="col-6 col-md-3">
                                <label className="form-label" htmlFor="kind">Kind</label>
                                <select id="kind" className="form-select" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                                    {kinds.map((kind) => (
                                        <option key={kind.value} value={kind.value}>{kind.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>Add</button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={channels}
                        rowKey={(row) => row.id}
                        emptyTitle="No channels yet"
                        emptyDescription="Without a channel, an order can say who sold it but not where it came from."
                    />
                </div>
            </div>
        </AppLayout>
    )
}
