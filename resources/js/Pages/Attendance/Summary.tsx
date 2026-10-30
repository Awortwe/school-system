import StaffLayout from '../../Layouts/StaffLayout';
import { DataTable, Search, pick, type Row } from '../../Components/Management';
export default function Summary(p: Row) { return <StaffLayout title="Term attendance summary"><Search action="/attendance/summary" filters={p.filters} fields={[pick('term_id', 'Term', p.terms), pick('section_id', 'Class', p.sections)]} /><DataTable data={p.students} columns={['name', 'class', 'present', 'absent', 'late', 'excused', 'total', 'percentage']} /></StaffLayout>; }
