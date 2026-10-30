import { Head, Link, usePage } from '@inertiajs/react';
import { useState, type PropsWithChildren, type ReactNode } from 'react';
import { GraduationCap, LayoutDashboard, Users, KeyRound, LogOut, Menu, X } from 'lucide-react';
import type { SharedProps } from '../types';
import { useCan } from '../Components/UI';

export default function StaffLayout({ title, children, actions }: PropsWithChildren<{ title: string; actions?: ReactNode }>) {
    const { auth, school, flash, currentYear, currentTerm } = usePage<SharedProps>().props;
    const { url } = usePage();
    const can = useCan();
    const [open, setOpen] = useState(false);
    const links = [
        { href: '/dashboard', label: 'Overview', icon: LayoutDashboard, permission: 'dashboard.view' },
        { href: '/students', label: 'Student records', icon: Users, permission: 'students.view' },
        { href: '/admissions', label: 'Admissions', icon: Users, permission: 'admissions.view' },
        { href: '/academics', label: 'Academic setup', icon: GraduationCap, permission: 'academics.view' },
        { href: '/attendance', label: 'Attendance', icon: Users, permission: 'attendance.view' },
        { href: '/timetable', label: 'Timetable', icon: LayoutDashboard, permission: 'timetable.view' },
        { href: '/results', label: 'Results & reports', icon: GraduationCap, permission: 'results.view' },
        { href: '/progress', label: 'Learning progress', icon: GraduationCap, permission: 'results.view' },
        { href: '/finance/fees', label: 'Fee setup', icon: LayoutDashboard, permission: 'fees.view' },
        { href: '/finance/invoices', label: 'Invoices', icon: LayoutDashboard, permission: 'invoices.view' },
        { href: '/finance/payments', label: 'Payments & receipts', icon: LayoutDashboard, permission: 'payments.view' },
        { href: '/finance/adjustments', label: 'Adjustments', icon: LayoutDashboard, permission: 'adjustments.view' },
        { href: '/finance/expenses', label: 'Expenses', icon: LayoutDashboard, permission: 'expenses.view' },
        { href: '/finance/income', label: 'Other income', icon: LayoutDashboard, permission: 'incomes.view' },
        { href: '/finance/refunds', label: 'Refunds', icon: LayoutDashboard, permission: 'payments.reverse' },
        { href: '/finance/cashbook', label: 'Cashbook', icon: LayoutDashboard, permission: 'reports.financial' },
        { href: '/finance/reports', label: 'Financial reports', icon: LayoutDashboard, permission: 'reports.financial' },
        { href: '/staff', label: 'Staff accounts', icon: Users, permission: 'staff.view' },
        { href: '/payroll', label: 'Payroll & salaries', icon: Users, permission: 'salaries.view' },
        { href: '/transport', label: 'School transport', icon: Users, permission: 'transport.view' },
        { href: '/inventory', label: 'Inventory & assets', icon: LayoutDashboard, permission: 'inventory.view' },
        { href: '/administration', label: 'Administration', icon: LayoutDashboard, permission: 'dashboard.view' },
        { href: '/documents', label: 'Private documents', icon: LayoutDashboard, permission: 'documents.view' },
        { href: '/imports', label: 'Student imports', icon: Users, permission: 'imports.view' },
        { href: '/report-jobs', label: 'Class PDF jobs', icon: GraduationCap, permission: 'results.view' },
        { href: '/settings', label: 'School settings', icon: LayoutDashboard, permission: 'settings.view' },
        { href: '/audit', label: 'Audit history', icon: LayoutDashboard, permission: 'audit.view' },
    ];
    return <div className="min-h-screen bg-[#f5f6fa]">
        <Head title={title} />
        <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-3">Skip to content</a>
        <div className="no-print flex items-center justify-between border-b bg-white p-4 lg:hidden"><span className="font-bold">{school.short_name} · Staff workspace</span><button aria-label={open ? 'Close navigation' : 'Open navigation'} aria-expanded={open} onClick={() => setOpen(!open)}>{open ? <X /> : <Menu />}</button></div>
        <aside className={`no-print ${open ? 'flex' : 'hidden'} inset-y-0 left-0 z-30 w-full flex-col overflow-y-auto border-r border-slate-200 bg-white p-6 lg:fixed lg:flex lg:w-64`}>
            <Link href="/dashboard" className="flex items-center gap-3"><div className="rounded-xl bg-brand-800 p-2.5 text-white"><GraduationCap size={26} /></div><div><div className="text-lg font-bold leading-tight">{school.short_name || 'Sanctuary'}</div><div className="mt-1 text-xs text-slate-500">Staff workspace</div></div></Link>
            <p className="mb-3 mt-12 text-[10px] font-bold uppercase tracking-[.2em] text-slate-400">School management</p>
            <nav className="space-y-2">{links.filter(link => can(link.permission)).map(({ href, label, icon: Icon }) => <Link onClick={() => setOpen(false)} key={href} href={href} aria-current={url.split('?')[0].startsWith(href) ? 'page' : undefined} className={`flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium ${url.startsWith(href) ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-50'}`}><Icon size={19} />{label}</Link>)}</nav>
            <div className="mt-auto pt-12"><div className="rounded-xl bg-slate-50 p-4"><div className="text-sm font-semibold">{auth.user?.name}</div><div className="mt-1 text-xs capitalize text-slate-500">{auth.user?.roles.join(', ').replace(/_/g, ' ') || 'Staff'}</div></div><Link href="/change-password" className="mt-4 flex items-center gap-2 px-4 py-2 text-sm text-slate-600"><KeyRound size={16} /> Change password</Link><Link href="/logout" method="post" as="button" className="flex w-full items-center gap-2 px-4 py-2 text-sm text-slate-600"><LogOut size={16} /> Sign out</Link></div>
        </aside>
        <div className="lg:pl-64"><header className="no-print flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-6 py-5 lg:px-10"><span className="font-semibold text-slate-700">{school.name}</span><span className="rounded-full border border-slate-200 px-3 py-1.5 text-xs text-slate-500">{currentYear?.name || 'No academic year set'} · {currentTerm?.name || 'No current term'}</span></header>
            <main id="main" className="mx-auto max-w-7xl p-5 sm:p-8 lg:p-10"><div className="mb-8 flex flex-wrap items-center justify-between gap-4"><div><p className="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-400">The Sanctuary · Staff only</p><h1 className="text-3xl font-semibold tracking-tight">{title}</h1></div>{actions}</div>
                {Object.entries(flash).filter(([key, value]) => ['success', 'error', 'warning'].includes(key) && typeof value === 'string').map(([key, value]) => <div key={key} role={key === 'error' ? 'alert' : 'status'} className={`mb-5 rounded-lg border p-4 text-sm ${key === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}>{String(value)}</div>)}
                {children}
            </main>
        </div>
    </div>;
}
