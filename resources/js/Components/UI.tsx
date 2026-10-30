import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import type { SharedProps } from '../types';

export const money = (minor: number) => new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(minor / 100);
export function useCan() {
    const { auth } = usePage<SharedProps>().props;
    return (permission: string) => !!auth.user && (auth.user.roles.includes('super_admin') || auth.user.permissions.includes(permission));
}
export function Empty({ children }: PropsWithChildren) { return <p className="py-10 text-center text-sm text-slate-500">{children}</p>; }
export function Field({ label, error, children }: PropsWithChildren<{ label: string; error?: string }>) {
    return <label className="block"><span className="label">{label}</span>{children}{error && <span className="error" role="alert">{error}</span>}</label>;
}
export function Pagination({ links }: { links: { url: string | null; label: string; active: boolean }[] }) {
    return <nav aria-label="Pagination" className="mt-5 flex flex-wrap gap-2">{links.map((link, i) => {
        const label = link.label.replace(/&laquo;|&raquo;/g, '').trim();
        return link.url ? <Link key={i} href={link.url} preserveScroll aria-current={link.active ? 'page' : undefined} className={link.active ? 'btn-primary' : 'btn-secondary'}>{label}</Link> : <span key={i} className="btn text-slate-400" aria-disabled="true">{label}</span>;
    })}</nav>;
}
