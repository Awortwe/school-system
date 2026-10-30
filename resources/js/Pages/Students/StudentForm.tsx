import { Link, useForm } from '@inertiajs/react';
import StaffLayout from '../../Layouts/StaffLayout';
import { Field } from '../../Components/UI';
import type { Option } from '../../types';

type Data = {
    admission_no: string; first_name: string; middle_name: string; last_name: string; date_of_birth: string;
    gender: string; category: string; status: string; admission_date: string; current_section_id: string;
    address: string; phone: string; email: string; nationality: string; religion: string; medical_notes: string; previous_school: string;
    guardian_name: string; guardian_last_name: string; guardian_phone: string; guardian_email: string; guardian_relationship: string;
};
export type StudentInput = Partial<Record<keyof Data, string | number | null>> & { id: number };
export default function StudentForm({ student, sections }: { student?: StudentInput; sections: Option[] }) {
    const defaults: Data = { admission_no: '', first_name: '', middle_name: '', last_name: '', date_of_birth: '', gender: '', category: 'day', status: 'active', admission_date: '', current_section_id: '', address: '', phone: '', email: '', nationality: '', religion: '', medical_notes: '', previous_school: '', guardian_name: '', guardian_last_name: '', guardian_phone: '', guardian_email: '', guardian_relationship: 'parent' };
    const initial = Object.fromEntries(Object.entries(defaults).map(([key, value]) => [key, String(student?.[key as keyof Data] ?? value)])) as Data;
    const form = useForm<Data>(initial);
    const input = (key: keyof Data, label: string, type = 'text', required = false) => <Field key={key} label={label} error={form.errors[key]}><input type={type} required={required} className="input" value={form.data[key]} onChange={e => form.setData(key, e.target.value)} /></Field>;
    const select = (key: keyof Data, label: string, values: string[]) => <Field label={label} error={form.errors[key]}><select className="input" value={form.data[key]} onChange={e => form.setData(key, e.target.value)}>{values.map(value => <option key={value} value={value}>{value || 'Not specified'}</option>)}</select></Field>;
    return <StaffLayout title={student ? 'Edit student record' : 'Add student'}><form className="space-y-6" onSubmit={e => { e.preventDefault(); student ? form.put(`/students/${student.id}`) : form.post('/students'); }}>
        {!!Object.keys(form.errors).length && <div role="alert" className="rounded-lg bg-red-50 p-4 text-sm text-red-800">Please correct the highlighted fields before saving.</div>}
        <section className="card-pad"><h2 className="mb-5 text-lg font-semibold">Student details</h2><div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{input('first_name', 'First name *', 'text', true)}{input('middle_name', 'Middle name')}{input('last_name', 'Last name *', 'text', true)}{input('date_of_birth', 'Date of birth', 'date')}{select('gender', 'Gender', ['', 'male', 'female'])}{input('admission_no', 'Admission number (blank to generate)')}{input('admission_date', 'Admission date', 'date')}{select('category', 'Student category', ['day', 'boarding'])}{select('status', 'Status', ['active', 'inactive', 'transferred', 'withdrawn', 'graduated', 'archived'])}<Field label="Class section" error={form.errors.current_section_id}><select className="input" value={form.data.current_section_id} onChange={e => form.setData('current_section_id', e.target.value)}><option value="">Unassigned</option>{sections.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></Field>{input('nationality', 'Nationality')}{input('previous_school', 'Previous school')}</div></section>
        <section className="card-pad"><h2 className="mb-5 text-lg font-semibold">Contact and additional details</h2><div className="grid gap-5 sm:grid-cols-2">{input('phone', 'Phone', 'tel')}{input('email', 'Email', 'email')}{input('address', 'Address')}{input('religion', 'Religion')}<Field label="Medical notes" error={form.errors.medical_notes}><textarea className="input" rows={3} value={form.data.medical_notes} onChange={e => form.setData('medical_notes', e.target.value)} /></Field></div></section>
        {!student && <section className="card-pad"><h2 className="mb-2 text-lg font-semibold">Primary guardian</h2><p className="mb-5 text-sm text-slate-500">Optional. This creates a guardian record linked to the student.</p><div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{input('guardian_name', 'Guardian first name')}{input('guardian_last_name', 'Guardian last name')}{input('guardian_phone', 'Guardian phone', 'tel')}{input('guardian_email', 'Guardian email', 'email')}{input('guardian_relationship', 'Relationship')}</div></section>}
        <div className="flex gap-3"><button className="btn-primary" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save student record'}</button><Link className="btn-secondary" href={student ? `/students/${student.id}` : '/students'}>Cancel</Link></div>
    </form></StaffLayout>;
}
