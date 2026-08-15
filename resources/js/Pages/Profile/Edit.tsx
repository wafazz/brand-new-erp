import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import PageHeader from '@/Components/PageHeader'
import FormField from '@/Components/FormField'
import TextInput from '@/Components/TextInput'

interface Props {
    profile: { name: string; email: string }
}

export default function ProfileEdit({ profile }: Props) {
    const details = useForm({ name: profile.name })
    const password = useForm({ current_password: '', password: '', password_confirmation: '' })

    return (
        <AppLayout>
            <Head title="Your profile" />

            <PageHeader title="Your profile" subtitle={profile.email} />

            <div className="row g-3">
                <div className="col-12 col-lg-6">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Details</h2></div>
                        <div className="card-body">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault()
                                    details.put('/profile')
                                }}
                            >
                                <FormField label="Name" name="name" required error={details.errors.name}>
                                    <TextInput name="name" value={details.data.name} invalid={Boolean(details.errors.name)} onChange={(v) => details.setData('name', v)} />
                                </FormField>

                                <FormField label="Email" name="email" hint="Ask an administrator to change your email.">
                                    <TextInput name="email" value={profile.email} disabled onChange={() => undefined} />
                                </FormField>

                                <button type="submit" className="btn btn-primary" disabled={details.processing}>
                                    {details.processing ? 'Saving…' : 'Save details'}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-6">
                    <div className="card h-100">
                        <div className="card-header bg-body"><h2 className="h6 mb-0">Password</h2></div>
                        <div className="card-body">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault()
                                    password.put('/profile/password', { onSuccess: () => password.reset() })
                                }}
                            >
                                <FormField label="Current password" name="current_password" required error={password.errors.current_password}>
                                    <TextInput
                                        name="current_password"
                                        type="password"
                                        value={password.data.current_password}
                                        invalid={Boolean(password.errors.current_password)}
                                        onChange={(v) => password.setData('current_password', v)}
                                    />
                                </FormField>

                                <FormField label="New password" name="password" required error={password.errors.password} hint="At least 12 characters.">
                                    <TextInput
                                        name="password"
                                        type="password"
                                        value={password.data.password}
                                        invalid={Boolean(password.errors.password)}
                                        onChange={(v) => password.setData('password', v)}
                                    />
                                </FormField>

                                <FormField label="Confirm new password" name="password_confirmation" required error={password.errors.password_confirmation}>
                                    <TextInput
                                        name="password_confirmation"
                                        type="password"
                                        value={password.data.password_confirmation}
                                        onChange={(v) => password.setData('password_confirmation', v)}
                                    />
                                </FormField>

                                <button type="submit" className="btn btn-primary" disabled={password.processing}>
                                    {password.processing ? 'Changing…' : 'Change password'}
                                </button>
                                <p className="form-text mb-0">
                                    If an administrator set your password when they added you, change it here now — nothing
                                    forces you to.
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
