import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import DataTable, { type Column } from '@/Components/DataTable'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'
import { useAuth } from '@/Hooks/useAuth'

interface Contact {
    id: string
    name: string
    position: string | null
    email: string | null
    phone: string | null
    is_primary: boolean
}

interface Props {
    supplier: {
        id: string
        code: string | null
        name: string
        registration_no: string | null
        tax_no: string | null
        email: string | null
        phone: string | null
        currency: string
        credit_limit: string
        payment_terms_days: string
        status: string
        notes: string | null
    }
    contacts: Contact[]
}

export default function SupplierShow({ supplier, contacts }: Props) {
    const { can } = useAuth()

    const columns: Column<Contact>[] = [
        {
            key: 'name',
            header: 'Name',
            render: (row) => (
                <span>{row.name} {row.is_primary ? <StatusBadge label="primary" tone="info" /> : null}</span>
            ),
        },
        { key: 'position', header: 'Position', render: (row) => row.position ?? '—' },
        { key: 'phone', header: 'Phone', render: (row) => row.phone ?? '—' },
        { key: 'email', header: 'Email', render: (row) => row.email ?? '—' },
    ]

    return (
        <AppLayout>
            <Head title={supplier.name} />

            <PageHeader
                title={supplier.name}
                subtitle={supplier.code ?? undefined}
                actions={
                    <>
                        <Link href="/suppliers" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {can('suppliers.update') ? <Link href={`/suppliers/${supplier.id}/edit`} className="btn btn-sm btn-primary">Edit</Link> : null}
                        {can('suppliers.delete') ? (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => router.delete(`/suppliers/${supplier.id}`)}>
                                Remove
                            </button>
                        ) : null}
                    </>
                }
            />

            <div className="row g-3">
                <div className="col-12 col-lg-5">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Details</h2></div>
                        <div className="card-body">
                            <dl className="row mb-0 small">
                                <dt className="col-5">Status</dt>
                                <dd className="col-7">
                                    <StatusBadge label={supplier.status} tone={supplier.status === 'active' ? 'success' : supplier.status === 'blocked' ? 'danger' : 'neutral'} />
                                </dd>
                                <dt className="col-5">Phone</dt>
                                <dd className="col-7">{supplier.phone ?? '—'}</dd>
                                <dt className="col-5">Email</dt>
                                <dd className="col-7">{supplier.email ?? '—'}</dd>
                                <dt className="col-5">Registration</dt>
                                <dd className="col-7">{supplier.registration_no ?? '—'}</dd>
                                <dt className="col-5">Tax number</dt>
                                <dd className="col-7">{supplier.tax_no ?? '—'}</dd>
                                <dt className="col-5">Credit limit</dt>
                                <dd className="col-7"><MoneyText amount={supplier.credit_limit} currency={supplier.currency} /></dd>
                                <dt className="col-5">Terms</dt>
                                <dd className="col-7">{supplier.payment_terms_days} days</dd>
                            </dl>
                            {supplier.notes ? <p className="small text-body-secondary mt-3 mb-0">{supplier.notes}</p> : null}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-7">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Contacts</h2></div>
                        <div className="card-body p-0">
                            <DataTable columns={columns} rows={contacts} rowKey={(row) => row.id} emptyTitle="No contacts" />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
