import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'

interface LeaveTypeRow {
    id: string
    code: string
    name: string
    days_per_year: string
    is_paid: boolean
    requires_document: boolean
    is_active: boolean
    requests: number
}

interface Props {
    types: LeaveTypeRow[]
}

export default function LeaveTypes({ types }: Props) {
    const [open, setOpen] = useState(false)

    const form = useForm({
        code: '',
        name: '',
        days_per_year: '0',
        is_paid: true,
        requires_document: false,
        is_active: true,
    })

    const columns: Column<LeaveTypeRow>[] = [
        {
            key: 'type',
            header: 'Type',
            render: (row) => (
                <div>
                    <span className="fw-semibold">{row.name}</span>
                    <div className="small text-body-secondary font-monospace">{row.code}</div>
                </div>
            ),
        },
        {
            key: 'days',
            header: 'Days a year',
            align: 'end',
            render: (row) => (Number(row.days_per_year) === 0 ? <span className="text-body-secondary">no limit</span> : Number(row.days_per_year).toFixed(1)),
        },
        { key: 'paid', header: 'Paid', render: (row) => (row.is_paid ? 'Yes' : 'No') },
        { key: 'document', header: 'Document', render: (row) => (row.requires_document ? 'Required' : '—') },
        { key: 'requests', header: 'Used', align: 'end', render: (row) => String(row.requests) },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge label={row.is_active ? 'active' : 'retired'} tone={row.is_active ? 'success' : 'neutral'} />,
        },
    ]

    return (
        <AppLayout>
            <Head title="Leave types" />

            <PageHeader
                title="Leave types"
                subtitle="What staff can ask for, and how much of it they get each year."
                actions={
                    <>
                        <Link href="/leave" className="btn btn-sm btn-outline-secondary">Leave</Link>
                        <button type="button" className="btn btn-sm btn-primary" onClick={() => setOpen((o) => !o)}>New type</button>
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
                                form.post('/leave-types', { onSuccess: () => { form.reset(); setOpen(false) } })
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
                                <label className="form-label" htmlFor="days_per_year">Days a year</label>
                                <input id="days_per_year" className="form-control text-end font-monospace" inputMode="decimal" value={form.data.days_per_year} onChange={(e) => form.setData('days_per_year', e.target.value)} />
                            </div>
                            <div className="col-12 col-md-2 d-grid">
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>Add</button>
                            </div>
                            <div className="col-12 d-flex gap-3">
                                <div className="form-check">
                                    <input id="is_paid" className="form-check-input" type="checkbox" checked={form.data.is_paid} onChange={(e) => form.setData('is_paid', e.target.checked)} />
                                    <label className="form-check-label small" htmlFor="is_paid">Paid leave</label>
                                </div>
                                <div className="form-check">
                                    <input id="requires_document" className="form-check-input" type="checkbox" checked={form.data.requires_document} onChange={(e) => form.setData('requires_document', e.target.checked)} />
                                    <label className="form-check-label small" htmlFor="requires_document">Needs a document, such as a medical certificate</label>
                                </div>
                            </div>
                            <div className="col-12">
                                <p className="form-text mb-0">Zero days a year means no annual limit — used for unpaid or compassionate leave.</p>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            <div className="card">
                <div className="card-body p-0">
                    <DataTable
                        columns={columns}
                        rows={types}
                        rowKey={(row) => row.id}
                        emptyTitle="No leave types yet"
                        emptyDescription="Until one exists, nobody in the company can ask for leave."
                    />
                </div>
                <div className="card-footer bg-body small text-body-secondary">
                    The document requirement is recorded but not enforced — nothing uploads a medical certificate yet.
                </div>
            </div>
        </AppLayout>
    )
}
