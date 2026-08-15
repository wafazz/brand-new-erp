import StatusBadge from './StatusBadge'

export interface ApprovalAction {
    id: string
    action: string
    comment: string | null
    actor: string
    at: string | null
}

export interface ApprovalPanel {
    id: string
    status: string
    amount: string
    current_sequence: number
    actions: ApprovalAction[]
}

interface Props {
    approval: ApprovalPanel | null
    emptyNote: string
}

const tone = (status: string) =>
    status === 'approved' ? 'success' : status === 'rejected' ? 'danger' : status === 'returned' ? 'warning' : 'info'

export default function ApprovalTrail({ approval, emptyNote }: Props) {
    return (
        <div className="card">
            <div className="card-header bg-body"><h2 className="h6 mb-0">Approval</h2></div>
            <div className="card-body">
                {approval === null ? (
                    <p className="small text-body-secondary mb-0">{emptyNote}</p>
                ) : (
                    <>
                        <div className="d-flex flex-wrap gap-2 align-items-center mb-3">
                            <StatusBadge label={approval.status} tone={tone(approval.status)} />
                            <span className="small text-body-secondary">
                                level {approval.current_sequence} · {Number(approval.amount).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </span>
                        </div>

                        <ol className="list-unstyled mb-0">
                            {approval.actions.map((action) => (
                                <li key={action.id} className="pb-2">
                                    <div className="small fw-semibold">{action.action.replace(/_/g, ' ')}</div>
                                    {action.comment ? <div className="small">{action.comment}</div> : null}
                                    <div className="small text-body-secondary">{action.actor} · {action.at}</div>
                                </li>
                            ))}
                        </ol>
                    </>
                )}
            </div>
        </div>
    )
}
