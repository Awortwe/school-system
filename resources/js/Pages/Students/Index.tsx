import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import StaffLayout from '../../Layouts/StaffLayout';
import { Empty, Pagination, money, useCan } from '../../Components/UI';
import type { Option, Paginated } from '../../types';

type Student = { id: number; name: string; admission_no: string; class: string | null; status: string; gender: string | null; balance_minor: number | null };
export default function Index({ students, sections, filters }: { students: Paginated<Student>; sections: Option[]; filters: { search?: string; section_id?: string; status?: string; category?: string } }) {
    const can = useCan();
    const [search, setSearch] = useState(filters.search || '');
    const [section, setSection] = useState(filters.section_id || '');
    const [status, setStatus] = useState(filters.status || '');
    return <StaffLayout title="Student records" actions={can('students.create') && <Link href="/students/create" className="btn-primary">+ Add student</Link>}>
        <form className="card-pad mb-6 grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_auto]" onSubmit={e => { e.preventDefault(); router.get('/students', { search, section_id: section, status }, { preserveState: true, replace: true }); }}>
            <label><span className="label">Search students</span><input className="input" placeholder="Name, admission number or phone" value={search} onChange={e => setSearch(e.target.value)} /></label>
            <label><span className="label">Class section</span><select className="input" value={section} onChange={e => setSection(e.target.value)}><option value="">All accessible classes</option>{sections.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label><span className="label">Status</span><select className="input" value={status} onChange={e => setStatus(e.target.value)}><option value="">All statuses</option>{['active', 'inactive', 'transferred', 'withdrawn', 'graduated', 'archived'].map(item => <option key={item}>{item}</option>)}</select></label>
            <div className="flex gap-2"><button className="btn-primary">Search</button><Link className="btn-secondary" href="/students" onClick={() => { setSearch(''); setSection(''); setStatus(''); }}>Reset</Link></div>
        </form>
        <p className="mb-3 text-sm text-slate-500">{students.total} student{students.total === 1 ? '' : 's'} · Showing {students.from || 0}–{students.to || 0}</p>
        <div className="table-wrap"><table className="table"><thead><tr><th>Name</th><th>Admission no.</th><th>Class</th><th>Status</th>{can('reports.financial') && <th>Account balance</th>}</tr></thead><tbody>{students.data.map(student => <tr key={student.id}><td><Link className="link" href={`/students/${student.id}`}>{student.name}</Link></td><td>{student.admission_no}</td><td>{student.class || 'Unassigned'}</td><td><span className={student.status === 'active' ? 'badge-green' : 'badge-slate'}>{student.status}</span></td>{can('reports.financial') && <td>{money(student.balance_minor ?? 0)}</td>}</tr>)}</tbody></table>{!students.data.length && <Empty>No matching students. Try another search or class.</Empty>}</div><Pagination links={students.links} />
    </StaffLayout>;
}
