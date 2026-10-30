import { Head, usePage } from '@inertiajs/react';
import { GraduationCap } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import type { SharedProps } from '../types';

export default function AuthLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { school, flash } = usePage<SharedProps>().props;
    return <main className="grid min-h-screen lg:grid-cols-2"><Head title={title} /><section className="flex flex-col justify-between bg-brand-800 p-8 text-white sm:p-14"><div className="flex items-center gap-3 text-lg font-semibold"><GraduationCap size={32} />{school.name}</div><div className="py-16"><p className="mb-5 text-xs font-semibold uppercase tracking-[.25em] text-indigo-200">School management</p><h1 className="max-w-lg text-4xl font-semibold leading-tight sm:text-5xl">A clear view of<br />every school day.</h1><p className="mt-6 max-w-md text-indigo-100">One workspace for authorised staff to manage student records, academics and school operations.</p></div><p className="text-xs text-indigo-200">Staff access only · Student and guardian records are managed by school staff.</p></section><section className="flex items-center justify-center bg-white p-6 sm:p-12"><div className="w-full max-w-sm"><h2 className="mb-2 text-3xl font-semibold">{title}</h2><p className="mb-8 text-sm text-slate-500">Use your school staff account.</p>{flash.success && <p role="status" className="mb-5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{flash.success}</p>}{children}</div></section></main>;
}
