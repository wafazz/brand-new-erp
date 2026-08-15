import { Head, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        post('/login')
    }

    return (
        <div className="d-flex align-items-center justify-content-center min-vh-100 bg-body-tertiary">
            <Head title="Sign in" />
            <div className="card shadow-sm" style={{ maxWidth: '24rem', width: '100%' }}>
                <div className="card-body p-4">
                    <h1 className="h5 mb-4">Sign in</h1>
                    <form onSubmit={submit} noValidate>
                        <div className="mb-3">
                            <label className="form-label" htmlFor="email">Email</label>
                            <input
                                id="email"
                                type="email"
                                className={`form-control ${errors.email ? 'is-invalid' : ''}`}
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                autoComplete="username"
                                required
                            />
                            {errors.email ? <div className="invalid-feedback">{errors.email}</div> : null}
                        </div>
                        <div className="mb-3">
                            <label className="form-label" htmlFor="password">Password</label>
                            <input
                                id="password"
                                type="password"
                                className={`form-control ${errors.password ? 'is-invalid' : ''}`}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoComplete="current-password"
                                required
                            />
                            {errors.password ? <div className="invalid-feedback">{errors.password}</div> : null}
                        </div>
                        <div className="form-check mb-3">
                            <input
                                id="remember"
                                type="checkbox"
                                className="form-check-input"
                                checked={data.remember}
                                onChange={(e) => setData('remember', e.target.checked)}
                            />
                            <label className="form-check-label" htmlFor="remember">Remember me</label>
                        </div>
                        <button type="submit" className="btn btn-primary w-100" disabled={processing}>
                            {processing ? 'Signing in…' : 'Sign in'}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    )
}
