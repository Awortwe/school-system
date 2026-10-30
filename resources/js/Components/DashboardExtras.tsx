import { Link, usePage } from '@inertiajs/react';
import { DataTable, Panel, type Row } from './Management';
import { useCan } from './UI';
export default function DashboardExtras() {
    const p = usePage().props as Row; const can = useCan();
    return <div className="mb-6 space-y-5">{p.administrativeTasks && <Panel title="Admissions tasks"><p className="text-sm">{p.administrativeTasks.applications} applications awaiting review.</p><Link href="/admissions" className="link text-sm">Review applications</Link></Panel>}{p.academicPerformance !== null && p.academicPerformance !== undefined && <Panel title="Approved academic performance"><p>Mean subject score: {Number(p.academicPerformance).toFixed(1)}%</p></Panel>}
        {Object.values(p.businessStats || {}).some(value => value !== null) && <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{Object.entries(p.businessStats).filter(([, value]) => value !== null).map(([key, value]) => <div className="card-pad" key={key}><p className="text-xs capitalize text-slate-500">{key.replace(/_/g, ' ')}</p><p className="mt-2 text-2xl font-semibold">{String(value)}</p></div>)}</div>}
        {p.finance && <p className="text-sm text-slate-500">Cashbook entries awaiting reconciliation: {p.finance.unreconciled_entries ?? 0}</p>}
        {p.teachingAssignments?.length > 0 && <Panel title="Your teaching assignments"><DataTable data={p.teachingAssignments} columns={['subject', 'section_id']} />{can('attendance.record') && <Link className="btn-secondary" href="/attendance">Record attendance</Link>}{can('results.enter') && <Link className="btn-secondary ml-2" href="/results">Enter scores</Link>}</Panel>}
        {p.todayLessons?.length > 0 && <Panel title="Your timetable today"><DataTable data={p.todayLessons} columns={['subject', 'period', 'room']} /></Panel>}
        {p.activities?.length > 0 && <details className="card-pad"><summary className="cursor-pointer font-semibold">Recent activity</summary><DataTable data={p.activities} columns={['created_at', 'description']} /></details>}
    </div>;
}
