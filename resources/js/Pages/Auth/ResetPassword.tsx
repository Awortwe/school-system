import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Field } from '../../Components/UI';
export default function ResetPassword({ email, token }: { email: string; token: string }) {
    const form = useForm({ email, token, password: '', password_confirmation: '' });
    return <AuthLayout title="Choose a new password"><form className="space-y-5" onSubmit={e => { e.preventDefault(); form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') }); }}><Field label="Staff email" error={form.errors.email}><input required type="email" autoComplete="username" className="input" value={form.data.email} onChange={e => form.setData('email', e.target.value)} /></Field>{(['password', 'password_confirmation'] as const).map(key => <Field key={key} label={key === 'password' ? 'New password' : 'Confirm new password'} error={form.errors[key]}><input required minLength={10} type="password" autoComplete="new-password" className="input" value={form.data[key]} onChange={e => form.setData(key, e.target.value)} /></Field>)}<p className="hint">Use at least 10 characters, with uppercase and lowercase letters and a number.</p><button className="btn-primary w-full" disabled={form.processing}>{form.processing ? 'Saving…' : 'Reset password'}</button></form></AuthLayout>;
}
