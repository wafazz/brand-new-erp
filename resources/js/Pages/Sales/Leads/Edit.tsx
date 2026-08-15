import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import LeadForm, { type LeadFormValues, type Reference } from './Form'

interface Props {
    lead: {
        id: string
        reference: string | null
        name: string
        phone: string | null
        email: string | null
        status: string
        pipeline_stage_id: string | null
        assigned_to: string | null
        branch_id: string | null
        estimated_value: string
        note: string | null
    }
    stages: Reference[]
    branches: Reference[]
    assignees: Reference[]
    statuses: Reference[]
}

export default function LeadEdit({ lead, stages, branches, assignees, statuses }: Props) {
    const { data, setData, put, processing, errors } = useForm<LeadFormValues>({
        reference: lead.reference ?? '',
        name: lead.name,
        phone: lead.phone ?? '',
        email: lead.email ?? '',
        status: lead.status,
        pipeline_stage_id: lead.pipeline_stage_id ?? '',
        assigned_to: lead.assigned_to ?? '',
        branch_id: lead.branch_id ?? '',
        estimated_value: lead.estimated_value,
        note: lead.note ?? '',
    })

    return (
        <AppLayout>
            <Head title={`Edit ${lead.name}`} />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    put(`/leads/${lead.id}`)
                }}
            >
                <PageHeader
                    title={`Edit ${lead.name}`}
                    subtitle={lead.reference ?? undefined}
                    actions={
                        <>
                            <Link href={`/leads/${lead.id}`} className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Save changes'}
                            </button>
                        </>
                    }
                />

                <LeadForm
                    values={data}
                    errors={errors}
                    stages={stages}
                    branches={branches}
                    assignees={assignees}
                    statuses={statuses}
                    onChange={(key, value) => setData((previous) => ({ ...previous, [key]: value }))}
                />
            </form>
        </AppLayout>
    )
}
