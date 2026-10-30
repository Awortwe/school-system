import { router } from '@inertiajs/react';
import StaffLayout from '../../Layouts/StaffLayout';
import { DataTable, type Row } from '../../Components/Management';
export default function Jobs(p: Row) { return <StaffLayout title="Class PDF jobs" actions={<button className="btn-secondary" onClick={() => router.reload()}>Refresh progress</button>}><DataTable data={p.jobs} columns={['id', 'class_section_id', 'term_id', 'status', 'error', 'created_at']} actions={row => row.status === 'completed' && <a className="link" href={`/report-jobs/${row.id}/download`}>Download class PDF</a>} /></StaffLayout>; }
