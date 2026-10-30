import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Field } from '../../Components/UI';

export default function Login({ status }: { status?: string }) {
    const form = useForm({ email: '', password: '', remember: false });
    return <AuthLayout title="Welcome back">{status && <p role="status">{status}</p>}<form className="space-y-5" onSubmit={e => { e.preventDefault(); form.post('/login', { onFinish: () => form.reset('password') }); }}>
        <Field label="Staff email" error={form.errors.email}><input required autoFocus autoComplete="username" type="email" className="input" value={form.data.email} onChange={e => form.setData('email', e.target.value)} /></Field>
        <Field label="Password" error={form.errors.password}><input required autoComplete="current-password" type="password" className="input" value={form.data.password} onChange={e => form.setData('password', e.target.value)} /></Field>
        <div className="flex items-center justify-between gap-3 text-sm"><label className="flex items-center gap-2"><input type="checkbox" checked={form.data.remember} onChange={e => form.setData('remember', e.target.checked)} />Remember me</label><Link href="/forgot-password" className="link">Forgot password?</Link></div>
        <button disabled={form.processing} className="btn-primary w-full">{form.processing ? 'Signing in…' : 'Sign in'}</button>
    </form></AuthLayout>;
}
