import { Head, Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatusBadge from '@/Components/StatusBadge'
import MoneyText from '@/Components/MoneyText'

interface Props {
    commission: {
        id: string
        recipient: string
        role: string | null
        order_id: string | null
        order_number: string | null
        type: string
        status: string
        is_provisional: boolean
        period: string
        currency: string
        basis_amount: string
        rate_type: string
        rate_applied: string
        amount: string
        calc_inputs: Record<string, unknown> | null
    }
    explanation: string
    transitions: string[]
    permissions: { approve: boolean; pay: boolean }
}

export default function CommissionShow({ commission, explanation, transitions, permissions }: Props) {
    const [reversing, setReversing] = useState(false)
    const move = useForm({ status: '', reason: '' })

    const allowed = (status: string) => (status === 'paid' ? permissions.pay : permissions.approve)

    return (
        <AppLayout>
            <Head title={`Commission ${commission.period}`} />

            <PageHeader
                title={`${commission.recipient} · ${commission.period}`}
                subtitle={`${commission.type}${commission.role ? ` · ${commission.role}` : ''}`}
                actions={
                    <>
                        <Link href="/commissions" className="btn btn-sm btn-outline-secondary">Back</Link>
                        {commission.order_id ? (
                            <Link href={`/orders/${commission.order_id}`} className="btn btn-sm btn-outline-primary">{commission.order_number}</Link>
                        ) : null}
                    </>
                }
            />

            <div className="d-flex flex-wrap gap-2 mb-3">
                <StatusBadge label={commission.status} tone={commission.status === 'paid' ? 'success' : commission.status === 'reversed' ? 'danger' : 'neutral'} />
                {commission.is_provisional ? <StatusBadge label="provisional" tone="warning" /> : <StatusBadge label="final" tone="info" />}
            </div>

            <div className="row g-3">
                <div className="col-12 col-lg-7">
                    <div className="card mb-3">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">How this was calculated</h2></div>
                        <div className="card-body">
                            <p className="mb-3">{explanation}</p>
                            <dl className="row mb-0 small">
                                <dt className="col-5">Basis</dt>
                                <dd className="col-7"><MoneyText amount={commission.basis_amount} currency={commission.currency} /></dd>
                                <dt className="col-5">Rate</dt>
                                <dd className="col-7 font-monospace">
                                    {commission.rate_type === 'percent' ? `${Number(commission.rate_applied).toFixed(2)}%` : Number(commission.rate_applied).toFixed(2)}
                                </dd>
                                <dt className="col-5">Amount</dt>
                                <dd className="col-7"><MoneyText amount={commission.amount} currency={commission.currency} /></dd>
                            </dl>
                        </div>
                    </div>

                    {commission.calc_inputs ? (
                        <div className="card">
                            <div className="card-header bg-body"><h2 className="h6 mb-0">Inputs recorded at accrual</h2></div>
                            <div className="card-body">
                                <pre className="small mb-0" style={{ whiteSpace: 'pre-wrap' }}>{JSON.stringify(commission.calc_inputs, null, 2)}</pre>
                            </div>
                            <div className="card-footer bg-body small text-body-secondary">
                                These are frozen. Changing a plan later never rewrites what was already accrued.
                            </div>
                        </div>
                    ) : null}
                </div>

                <div className="col-12 col-lg-5">
                    <div className="card">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Move this commission</h2></div>
                        <div className="card-body">
                            {transitions.length === 0 ? (
                                <p className="small text-body-secondary mb-0">This commission has reached the end of its life.</p>
                            ) : (
                                <div className="d-flex flex-wrap gap-2 mb-3">
                                    {transitions.filter((status) => status !== 'reversed').map((status) => (
                                        <button
                                            key={status}
                                            type="button"
                                            className="btn btn-sm btn-outline-primary"
                                            disabled={!allowed(status) || move.processing}
                                            title={allowed(status) ? undefined : 'Your role cannot make this move.'}
                                            onClick={() => {
                                                move.transform(() => ({ status, reason: '' }))
                                                move.post(`/commissions/${commission.id}/transition`, { preserveScroll: true })
                                            }}
                                        >
                                            Mark {status}
                                        </button>
                                    ))}
                                    {transitions.includes('reversed') ? (
                                        <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setReversing((open) => !open)}>
                                            Reverse
                                        </button>
                                    ) : null}
                                </div>
                            )}

                            {reversing ? (
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault()
                                        move.transform((data) => ({ ...data, status: 'reversed' }))
                                        move.post(`/commissions/${commission.id}/transition`, { preserveScroll: true, onSuccess: () => setReversing(false) })
                                    }}
                                >
                                    <label className="form-label" htmlFor="reason">Why is this being reversed?</label>
                                    <input
                                        id="reason"
                                        className={`form-control mb-2 ${move.errors.reason ? 'is-invalid' : ''}`}
                                        value={move.data.reason}
                                        onChange={(e) => move.setData('reason', e.target.value)}
                                    />
                                    <button type="submit" className="btn btn-sm btn-outline-danger" disabled={move.processing}>
                                        Reverse commission
                                    </button>
                                    <p className="form-text mb-0">
                                        A reversal writes a contra entry. The original accrual is never edited or deleted.
                                    </p>
                                </form>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
