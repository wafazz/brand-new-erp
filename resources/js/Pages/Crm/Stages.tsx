import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'

interface Stage {
    id: string
    code: string
    name: string
    probability: string
    sort: number
    is_won: boolean
    is_lost: boolean
    leads: number
}

interface Props {
    stages: Stage[]
}

export default function PipelineStages({ stages }: Props) {
    const [open, setOpen] = useState(false)

    const form = useForm({
        code: '',
        name: '',
        probability: '0',
        sort: String(stages.length * 10),
        is_won: false,
        is_lost: false,
    })

    const columns: Column<Stage>[] = [
        {
            key: 'stage',
            header: 'Stage',
            render: (row) => (
                <div>
                    <span className="fw-semibold">{row.name}</span>
                    <div className="small text-body-secondary font-monospace">{row.code}</div>
                </div>
            ),
        },
        { key: 'probability', header: 'Odds', align: 'end', render: (row) => `${row.probability}%` },
        { key: 'sort', header: 'Order', align: 'end', render: (row) => String(row.sort) },
        { key: 'leads', header: 'Leads', align: 'end', render: (row) => String(row.leads) },
        {
            key: 'outcome',
            header: 'Outcome',
            render: (row) =>
                row.is_won
                    ? <StatusBadge label="won" tone="success" />
                    : row.is_lost
                        ? <StatusBadge label="lost" tone="danger" />
                        : <span className="text-body-secondary small">open</span>,
        },
    ]

    return (
        <AppLayout>
            <Head title="Pipeline stages" />

            <PageHeader
                title="Pipeline stages"
                subtitle="The steps a lead moves through, and how likely each one is to close."
                actions={
                    <>
                        <Link href="/pipeline" className="btn btn-sm btn-outline-secondary">Board</Link>
                        <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>New stage</button>
                    </>
                }
            />

            {open ? (
                <div className="card mb-3 border-primary">
                    <div className="card-body">
                        <form
                            className="row g-2 align-items-end"
                            onSubmit={(event) => {
                                event.preventDefault()
                                form.post('/pipeline/stages', { onSuccess: () => { form.reset(); setOpen(false) } })
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
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="probability">Odds %</label>
                                <input id="probability" className={`form-control text-end font-monospace ${form.errors.probability ? 'is-invalid' : ''}`} inputMode="numeric" value={form.data.probability} onChange={(e) => form.setData('probability', e.target.value)} />
                                {form.errors.probability ? <div className="invalid-feedback d-block">{form.errors.probability}</div> : null}
                            </div>
                            <div className="col-6 col-md-2">
                                <label className="form-label" htmlFor="sort">Order</label>
                                <input id="sort" className="form-control text-end font-monospace" inputMode="numeric" value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)} />
                            </div>
                            <div className="col-6 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>Add</button>
                            </div>
                            <div className="col-12 d-flex gap-3">
                                <div className="form-check">
                                    <input id="is_won" className="form-check-input" type="checkbox" checked={form.data.is_won} onChange={(e) => form.setData('is_won', e.target.checked)} />
                                    <label className="form-check-label small" htmlFor="is_won">Reaching this stage means won</label>
                                </div>
                                <div className="form-check">
                                    <input id="is_lost" className="form-check-input" type="checkbox" checked={form.data.is_lost} onChange={(e) => form.setData('is_lost', e.target.checked)} />
                                    <label className="form-check-label small" htmlFor="is_lost">Reaching this stage means lost</label>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={stages}
                        rowKey={(row) => row.id}
                        emptyTitle="No stages yet"
                        emptyDescription="Without stages there is no pipeline — leads sit in one undifferentiated pile."
                    />
                </div>
                <div className="card-footer bg-body small text-body-secondary">
                    Moving a lead into a won or lost stage sets its status to match, so the board and the lead list
                    never disagree.
                </div>
            </div>
        </AppLayout>
    )
}
