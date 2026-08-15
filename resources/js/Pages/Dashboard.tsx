import { Head } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import StatCard from '@/Components/StatCard'

interface Props {
    companyName: string
    branchCount: number
    userCount: number
    enabledModules: number
}

export default function Dashboard({ companyName, branchCount, userCount, enabledModules }: Props) {
    return (
        <AppLayout navigation={[{ label: 'Dashboard', href: '/dashboard', icon: 'bi-speedometer2' }]}>
            <Head title="Dashboard" />
            <PageHeader title="Dashboard" subtitle={companyName} />
            <div className="row g-3">
                <div className="col-12 col-md-4">
                    <StatCard label="Branches" value={String(branchCount)} />
                </div>
                <div className="col-12 col-md-4">
                    <StatCard label="Users" value={String(userCount)} />
                </div>
                <div className="col-12 col-md-4">
                    <StatCard label="Modules enabled" value={String(enabledModules)} />
                </div>
            </div>
        </AppLayout>
    )
}
