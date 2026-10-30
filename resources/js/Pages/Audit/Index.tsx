import StaffLayout from '../../Layouts/StaffLayout';
import { DataTable, Search, text, pick, type Row } from '../../Components/Management';
export default function Audit(p: Row) { return <StaffLayout title="Audit history"><Search action="/audit" filters={p.filters} fields={[text('search', 'Search'), pick('user_id', 'Staff', p.users), text('from', 'From', false, 'date'), text('to', 'To', false, 'date')]} /><DataTable data={p.logs} columns={['created_at', 'user', 'event', 'description', 'auditable_type', 'auditable_id', 'reason']} /></StaffLayout>; }
