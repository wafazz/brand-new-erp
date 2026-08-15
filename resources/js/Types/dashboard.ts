export interface Slice {
    slice: string
    revenue: string
    orders: string
}

export interface DashboardFigures {
    variant: 'management' | 'sales' | 'marketing' | 'marketer' | 'salesperson'
    period: string
    orders: number
    revenue: string
    cost?: string
    margin?: string
    outstanding?: string
    commission_payable?: string
    commission_pending?: string
    commission_paid?: string
    target?: string
    attainment_percent?: string | null
    open_leads?: number
    top_salespeople?: Slice[]
    top_campaigns?: Slice[]
    team_breakdown?: Slice[]
    campaign_breakdown?: Slice[]
    channel_breakdown?: Slice[]
    marketer_breakdown?: Slice[]
}
