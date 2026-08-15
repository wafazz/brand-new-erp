import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import StatusBadge from '@/Components/StatusBadge'
import DataTable, { type Column } from '@/Components/DataTable'
import { useAuth } from '@/Hooks/useAuth'

interface Contact {
    id: string
    name: string
    position: string | null
    email: string | null
    phone: string | null
    is_primary: boolean
}

interface Address {
    id: string
    label: string | null
    type: string
    line1: string
    city: string | null
    state: string | null
    postcode: string | null
}

interface Props {
    customer: {
        id: string
        code: string
        name: string
        type: string
        company_name: string | null
        email: string | null
        phone: string | null
        tax_no: string | null
        status: string
        currency: string
        credit_limit: string
        payment_terms_days: number
        group: string | null
        owner: string | null
        notes: string | null
    }
    contacts: Contact[]
    addresses: Address[]
    orderSummary: { orders: string; revenue: string; outstanding: string }
}

export default function CustomerShow({ customer, contacts, addresses, orderSummary }: Props) {
    const { can } = useAuth()

    const contactColumns: Column<Contact>[] = [
        {
            key: 'name',
            header: 'Name',
            render: (row) => (
                <span>
                    {row.name} {row.is_primary ? <StatusBadge label="primary" tone="info" /> : null}
                </span>
            ),
        },
        { key: 'position', header: 'Position', render: (row) => row.position ?? '—' },
        { key: 'phone', header: 'Phone', render: (row) => row.phone ?? '—' },
        { key: 'email', header: 'Email', render: (row) => row.email ?? '—' },
    ]

    const addressColumns: Column<Address>[] = [
        { key: 'type', header: 'Type', render: (row) => row.type },
        {
            key: 'address',
            header: 'Address',
            render: (row) => (
                <span>
                    {row.line1}
                    {row.city ? `, ${row.city}` : ''}
                    {row.postcode ? ` ${row.postcode}` : ''}
                    {row.state ? `, ${row.state}` : ''}
                </span>
            ),
        },
    ]

    const remove = () => {
        router.delete(`/customers/${customer.id}`)
    }

    return (
        <AppLayout>
            <Head title={customer.name} />

            <PageHeader
                title={customer.name}
                subtitle={`${customer.code} · ${customer.type === 'business' ? 'Business' : 'Individual'}${customer.group ? ` · ${customer.group}` : ''}`}
                actions={
                    <>
                        <Link href="/customers" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {can('customers.update') ? (
                            <Link href={`/customers/${customer.id}/edit`} className="btn btn-sm btn-primary">Edit</Link>
                        ) : null}
                        {can('customers.delete') ? (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={remove}>Remove</button>
                        ) : null}
                    </>
                }
            />

            <div className="row g-3 mb-4">
                <div className="col-6 col-lg-3">
                    <StatCard label="Orders" value={orderSummary.orders} />
                </div>
                <div className="col-6 col-lg-3">
                    <StatCard label="Revenue" value={`${customer.currency} ${Number(orderSummary.revenue).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`} />
                </div>
                <div className="col-6 col-lg-3">
                    <StatCard
                        label="Outstanding"
                        value={`${customer.currency} ${Number(orderSummary.outstanding).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                        tone={Number(orderSummary.outstanding) > 0 ? 'warning' : 'default'}
                    />
                </div>
                <div className="col-6 col-lg-3">
                    <StatCard
                        label="Credit limit"
                        value={`${customer.currency} ${Number(customer.credit_limit).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}
                        hint={`${customer.payment_terms_days} day terms`}
                    />
                </div>
            </div>

            <div className="row g-3">
                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Details</h2></div>
                        <div className="card-body">
                            <dl className="row mb-0 small">
                                <dt className="col-5">Status</dt>
                                <dd className="col-7">
                                    <StatusBadge label={customer.status} tone={customer.status === 'active' ? 'success' : 'neutral'} />
                                </dd>
                                <dt className="col-5">Phone</dt>
                                <dd className="col-7">{customer.phone ?? '—'}</dd>
                                <dt className="col-5">Email</dt>
                                <dd className="col-7">{customer.email ?? '—'}</dd>
                                <dt className="col-5">Tax number</dt>
                                <dd className="col-7">{customer.tax_no ?? '—'}</dd>
                                <dt className="col-5">Owner</dt>
                                <dd className="col-7">{customer.owner ?? 'Unassigned'}</dd>
                            </dl>
                            {customer.notes ? <p className="small text-body-secondary mt-3 mb-0">{customer.notes}</p> : null}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Contacts</h2></div>
                        <div className="card-body">
                            <DataTable columns={contactColumns} rows={contacts} rowKey={(r) => r.id} emptyTitle="No contacts" />
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Addresses</h2></div>
                        <div className="card-body">
                            <DataTable columns={addressColumns} rows={addresses} rowKey={(r) => r.id} emptyTitle="No addresses" />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
