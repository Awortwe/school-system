import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Field } from '../../Components/UI';
export default function ForgotPassword() {
    const form = useForm({ email: '' });
    return <AuthLayout title="Reset your password"><p className="mb-6 text-sm text-slate-600">Enter your staff email to request a reset link.</p><form className="space-y-5" onSubmit={e => { e.preventDefault(); form.post('/forgot-password'); }}><Field label="Staff email" error={form.errors.email}><input required type="email" autoComplete="username" className="input" value={form.data.email} onChange={e => form.setData('email', e.target.value)} /></Field><button className="btn-primary w-full" disabled={form.processing}>{form.processing ? 'Sending…' : 'Send reset link'}</button><Link className="link block text-center text-sm" href="/login">Back to sign in</Link></form></AuthLayout>;
}
