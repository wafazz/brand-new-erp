import { Head, Link } from '@inertiajs/react'

interface Line {
    id: string
    sku: string | null
    name: string
    quantity: string
    unit_price: string
    line_total: string
}

interface Tender {
    id: string
    method: string
    amount: string
}

interface Props {
    receipt: {
        order_number: string
        placed_at: string | null
        cashier: string | null
        branch: string | null
        register: string | null
        customer_name: string
        currency: string
        subtotal: string
        discount_amount: string
        tax_amount: string
        total: string
        refunded: boolean
    }
    company: { name: string | null }
    lines: Line[]
    tenders: Tender[]
}

const money = (v: string) => Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function Receipt({ receipt, company, lines, tenders }: Props) {
    const paid = tenders.filter((t) => Number(t.amount) > 0)
    const refunds = tenders.filter((t) => Number(t.amount) < 0)
    const tendered = paid.reduce((sum, t) => sum + Number(t.amount), 0)
    const change = tendered - Number(receipt.total)

    return (
        <>
            <Head title={`Receipt ${receipt.order_number}`} />

            <div className="receipt-page">
                <div className="d-print-none p-3 border-bottom d-flex gap-2">
                    <button type="button" className="btn btn-sm btn-primary" onClick={() => window.print()}>Print</button>
                    <Link href="/pos" className="btn btn-sm btn-outline-secondary">Back to the till</Link>
                </div>

                <div className="receipt mx-auto p-3">
                    <div className="text-center mb-3">
                        <div className="fw-bold">{company.name ?? 'Receipt'}</div>
                        {receipt.branch ? <div className="small">{receipt.branch}</div> : null}
                        {receipt.register ? <div className="small">{receipt.register}</div> : null}
                    </div>

                    {receipt.refunded ? (
                        <div className="text-center fw-bold border border-dark py-1 mb-2">REFUNDED</div>
                    ) : null}

                    <div className="small mb-2">
                        <div className="d-flex justify-content-between"><span>{receipt.order_number}</span><span>{receipt.placed_at}</span></div>
                        <div className="d-flex justify-content-between"><span>{receipt.customer_name}</span><span>{receipt.cashier}</span></div>
                    </div>

                    <hr className="my-2" />

                    <table className="w-100 small">
                        <tbody>
                            {lines.map((line) => (
                                <tr key={line.id}>
                                    <td colSpan={2}>
                                        <div>{line.name}</div>
                                        <div className="text-muted">
                                            {Number(line.quantity).toFixed(2)} × {money(line.unit_price)}
                                        </div>
                                    </td>
                                    <td className="text-end align-bottom">{money(line.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <hr className="my-2" />

                    <table className="w-100 small">
                        <tbody>
                            <tr><td>Subtotal</td><td className="text-end">{money(receipt.subtotal)}</td></tr>
                            {Number(receipt.discount_amount) > 0 ? (
                                <tr><td>Discount</td><td className="text-end">−{money(receipt.discount_amount)}</td></tr>
                            ) : null}
                            {Number(receipt.tax_amount) > 0 ? (
                                <tr><td>Tax</td><td className="text-end">{money(receipt.tax_amount)}</td></tr>
                            ) : null}
                            <tr className="fw-bold border-top">
                                <td>Total {receipt.currency}</td>
                                <td className="text-end">{money(receipt.total)}</td>
                            </tr>
                        </tbody>
                    </table>

                    <hr className="my-2" />

                    <table className="w-100 small">
                        <tbody>
                            {paid.map((tender) => (
                                <tr key={tender.id}>
                                    <td className="text-capitalize">{tender.method.replace('_', ' ')}</td>
                                    <td className="text-end">{money(tender.amount)}</td>
                                </tr>
                            ))}
                            {change > 0 ? (
                                <tr><td>Change</td><td className="text-end">{money(String(change))}</td></tr>
                            ) : null}
                            {refunds.map((tender) => (
                                <tr key={tender.id} className="fw-bold">
                                    <td className="text-capitalize">Refund · {tender.method.replace('_', ' ')}</td>
                                    <td className="text-end">{money(tender.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="text-center small mt-3">
                        <div>Thank you</div>
                        <div className="text-muted">Goods returnable with this receipt</div>
                    </div>
                </div>
            </div>
        </>
    )
}
