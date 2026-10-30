import { Link } from '@inertiajs/react';
import StaffLayout from '../Layouts/StaffLayout';
import DashboardExtras from '../Components/DashboardExtras';
import { Empty, money, useCan } from '../Components/UI';

type Student = { id: number; name: string; admission_no: string; class: string | null; status: string };
type Props = {
    stats: { students: number | null; classes: number | null; attendance_rate: number | null; attendance_total: number; pending_results: number | null };
    finance: null | { collections: number; outstanding: number; expenses_month: number; recentPayments: { id: number; student: string; receipt_no: string; amount_minor: number; payment_date: string; method: string }[] };
    recentStudents: Student[];
    assignedClasses: { id: number; name: string }[];
    upcomingEvents: { id: number; title: string; start_date: string }[];
    announcements: { id: number; title: string; body: string }[];
};
export default function Dashboard({ stats, finance, recentStudents, assignedClasses, upcomingEvents, announcements }: Props) {
    const can = useCan();
    const cards = [
        ...(stats.students !== null ? [{ label: 'Active students', value: stats.students, detail: 'Within your access' }] : []),
        ...(stats.classes !== null ? [{ label: 'Class sections', value: stats.classes, detail: 'Within your access' }] : []),
        ...(can('attendance.view') ? [{ label: 'Attendance today', value: stats.attendance_rate === null ? '—' : `${stats.attendance_rate}%`, detail: `${stats.attendance_total} attendance records` }] : []),
        ...(stats.pending_results !== null ? [{ label: can('results.approve') ? 'Awaiting approval' : 'Draft result sheets', value: stats.pending_results, detail: 'Current term, when configured' }] : []),
    ];
    return <StaffLayout title="School overview" actions={can('students.create') && <Link className="btn-primary" href="/students/create">+ Add student</Link>}>
        <div className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{cards.map(card => <section key={card.label} className="card-pad"><p className="text-sm text-slate-500">{card.label}</p><p className="my-4 text-3xl font-semibold tabular-nums">{card.value}</p><p className="text-xs text-slate-400">{card.detail}</p></section>)}</div>
        {finance && <section className="mb-8"><h2 className="mb-4 text-lg font-semibold">Operational financial summary</h2><div className="grid gap-4 sm:grid-cols-3">{[{ label: 'Collections · current term or all time', value: finance.collections }, { label: 'Outstanding invoices · all terms', value: finance.outstanding }, { label: 'Approved expenses · this month', value: finance.expenses_month }].map(card => <div key={card.label} className="card-pad"><p className="text-xs text-slate-500">{card.label}</p><p className="mt-3 text-2xl font-semibold">{money(card.value)}</p></div>)}</div></section>}
        <DashboardExtras />
        <div className="grid items-start gap-6 xl:grid-cols-[1fr_320px]"><div className="space-y-6">{can('students.view') && <section className="card overflow-hidden"><div className="flex items-center justify-between border-b border-slate-100 p-5"><h2 className="font-semibold">Recently added students</h2><Link href="/students" className="link text-sm">All students →</Link></div>{recentStudents.length ? <div className="overflow-x-auto"><table className="table"><thead><tr><th>Student</th><th>Admission no.</th><th>Class</th><th>Status</th></tr></thead><tbody>{recentStudents.map(student => <tr key={student.id}><td><Link className="link" href={`/students/${student.id}`}>{student.name}</Link></td><td>{student.admission_no}</td><td>{student.class || 'Unassigned'}</td><td><span className={student.status === 'active' ? 'badge-green' : 'badge-slate'}>{student.status}</span></td></tr>)}</tbody></table></div> : <Empty>No student records within your access yet.</Empty>}</section>}
        {finance && <section className="card overflow-hidden"><h2 className="border-b p-5 font-semibold">Recent confirmed payments</h2>{finance.recentPayments.length ? <div className="overflow-x-auto"><table className="table"><thead><tr><th>Receipt</th><th>Student</th><th>Date</th><th>Amount</th></tr></thead><tbody>{finance.recentPayments.map(payment => <tr key={payment.id}><td>{payment.receipt_no}</td><td>{payment.student}</td><td>{payment.payment_date}</td><td>{money(payment.amount_minor)}</td></tr>)}</tbody></table></div> : <Empty>No confirmed payments recorded.</Empty>}</section>}
        {can('classes.view') && <section className="card-pad"><h2 className="mb-4 font-semibold">{can('academics.manage') ? 'Class sections' : 'Your class sections'}</h2><div className="flex flex-wrap gap-2">{assignedClasses.map(section => can('students.view') ? <Link className="btn-secondary" href={`/students?section_id=${section.id}`} key={section.id}>{section.name}</Link> : <span className="badge-slate" key={section.id}>{section.name}</span>)}</div>{!assignedClasses.length && <Empty>No classes assigned yet.</Empty>}</section>}</div>
        <div className="space-y-6"><section className="card-pad"><h2 className="mb-4 font-semibold">Upcoming events</h2>{upcomingEvents.map(event => <div key={event.id} className="border-b border-slate-100 py-3 last:border-0"><p className="text-xs font-medium text-brand-800">{event.start_date.slice(0, 10)}</p><p className="mt-1 text-sm">{event.title}</p></div>)}{!upcomingEvents.length && <Empty>No upcoming events.</Empty>}</section><section className="card-pad"><h2 className="mb-4 font-semibold">Staff announcements</h2>{announcements.map(item => <article key={item.id} className="border-b py-3 last:border-0"><h3 className="text-sm font-semibold">{item.title}</h3><p className="mt-2 whitespace-pre-wrap text-sm text-slate-500">{item.body}</p></article>)}{!announcements.length && <Empty>No current announcements.</Empty>}</section></div></div>
    </StaffLayout>;
}
