import { Head, Link, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import LeadForm, { EMPTY_LEAD, type LeadFormValues, type Reference } from './Form'

interface Props {
    stages: Reference[]
    branches: Reference[]
    assignees: Reference[]
    statuses: Reference[]
}

export default function LeadCreate({ stages, branches, assignees, statuses }: Props) {
    const { data, setData, post, processing, errors } = useForm<LeadFormValues>({ ...EMPTY_LEAD })

    return (
        <AppLayout>
            <Head title="New lead" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    post('/leads')
                }}
            >
                <PageHeader
                    title="New lead"
                    subtitle="Capture it now — attribution follows the lead all the way through to commission."
                    actions={
                        <>
                            <Link href="/leads" className="btn btn-sm btn-outline-secondary">Cancel</Link>
                            <button type="submit" className="btn btn-sm btn-primary" disabled={processing}>
                                {processing ? 'Saving…' : 'Capture lead'}
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
