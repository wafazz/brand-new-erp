import { Head, Link } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'
import SliceTable from '@/Components/SliceTable'
import { useAuth } from '@/Hooks/useAuth'
import type { DashboardFigures } from '@/Types/dashboard'

interface Props {
    companyName: string
    figures: DashboardFigures
    availableVariants: string[]
}

const titles: Record<DashboardFigures['variant'], string> = {
    management: 'Management',
    sales: 'Sales',
    marketing: 'Marketing',
    marketer: 'My marketing',
    salesperson: 'My sales',
}

function money(currency: string, value: string | undefined): string {
    if (value === undefined) {
        return '—'
    }

    const parsed = Number(value)

    return `${currency} ${Number.isFinite(parsed) ? parsed.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : value}`
}

export default function Dashboard({ companyName, figures, availableVariants }: Props) {
    const { company } = useAuth()
    const currency = company?.currency ?? 'MYR'

    const attainment = figures.attainment_percent
    const attainmentTone = attainment === null || attainment === undefined
        ? 'default'
        : Number(attainment) >= 100
            ? 'success'
            : 'warning'

    return (
        <AppLayout navigation={[{ label: 'Dashboard', href: '/dashboard', icon: 'bi-speedometer2' }]}>
            <Head title={`${titles[figures.variant]} dashboard`} />

            <PageHeader
                title={`${titles[figures.variant]} dashboard`}
                subtitle={`${companyName} · ${figures.period} · figures limited to what your role and data scope permit`}
                actions={
                    availableVariants.length > 1 ? (
                        <div className="btn-group btn-group-sm" role="group">
                            {availableVariants.map((variant) => (
                                <Link
                                    key={variant}
                                    href={`/dashboard?view=${variant}`}
                                    className={`btn ${variant === figures.variant ? 'btn-primary' : 'btn-outline-secondary'}`}
                                >
                                    {titles[variant as DashboardFigures['variant']]}
                                </Link>
                            ))}
                        </div>
                    ) : null
                }
            />

            <div className="row g-3 mb-4">
                <div className="col-6 col-lg-3">
                    <StatCard label="Orders" value={String(figures.orders)} />
                </div>
                <div className="col-6 col-lg-3">
                    <StatCard label="Revenue" value={money(currency, figures.revenue)} />
                </div>
                {figures.margin !== undefined ? (
                    <div className="col-6 col-lg-3">
                        <StatCard label="Margin" value={money(currency, figures.margin)} />
                    </div>
                ) : null}
                {figures.outstanding !== undefined ? (
                    <div className="col-6 col-lg-3">
                        <StatCard label="Outstanding" value={money(currency, figures.outstanding)} tone="warning" />
                    </div>
                ) : null}
                {figures.target !== undefined ? (
                    <div className="col-6 col-lg-3">
                        <StatCard
                            label="Target"
                            value={money(currency, figures.target)}
                            hint={attainment === null || attainment === undefined ? 'No target set' : `${attainment}% attained`}
                            tone={attainmentTone}
                        />
                    </div>
                ) : null}
                {figures.commission_pending !== undefined ? (
                    <div className="col-6 col-lg-3">
                        <StatCard label="Commission pending" value={money(currency, figures.commission_pending)} />
                    </div>
                ) : null}
                {figures.commission_paid !== undefined ? (
                    <div className="col-6 col-lg-3">
                        <StatCard label="Commission paid" value={money(currency, figures.commission_paid)} tone="success" />
                    </div>
                ) : null}
                {figures.commission_payable !== undefined ? (
                    <div className="col-6 col-lg-3">
                        <StatCard label="Commission payable" value={money(currency, figures.commission_payable)} />
                    </div>
                ) : null}
                {figures.open_leads !== undefined ? (
                    <div className="col-6 col-lg-3">
                        <StatCard label="Open leads" value={String(figures.open_leads)} />
                    </div>
                ) : null}
            </div>

            <div className="row g-3">
                {figures.top_salespeople ? (
                    <div className="col-12 col-lg-6">
                        <SliceTable title="Top salespeople" rows={figures.top_salespeople} currency={currency} />
                    </div>
                ) : null}
                {figures.top_campaigns ? (
                    <div className="col-12 col-lg-6">
                        <SliceTable title="Top campaigns" rows={figures.top_campaigns} currency={currency} />
                    </div>
                ) : null}
                {figures.team_breakdown ? (
                    <div className="col-12 col-lg-6">
                        <SliceTable title="By team" rows={figures.team_breakdown} currency={currency} />
                    </div>
                ) : null}
                {figures.campaign_breakdown ? (
                    <div className="col-12 col-lg-6">
                        <SliceTable title="By campaign" rows={figures.campaign_breakdown} currency={currency} />
                    </div>
                ) : null}
                {figures.channel_breakdown ? (
                    <div className="col-12 col-lg-6">
                        <SliceTable title="By channel" rows={figures.channel_breakdown} currency={currency} />
                    </div>
                ) : null}
                {figures.marketer_breakdown ? (
                    <div className="col-12 col-lg-6">
                        <SliceTable title="By marketer" rows={figures.marketer_breakdown} currency={currency} />
                    </div>
                ) : null}
            </div>
        </AppLayout>
    )
}
