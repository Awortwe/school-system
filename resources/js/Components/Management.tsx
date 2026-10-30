import { Link, router, useForm } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Empty, Field, Pagination, money, useCan } from './UI';

export type Row = Record<string, any>;
export type Input = { key: string; label: string; type?: string; required?: boolean; options?: any[]; value?: any; fields?: Input[] };
export const options = (rows: any[] = [], key = 'name') => rows.map(row => ({ value: row.id ?? row.value, label: row[key] ?? row.label ?? row.title ?? row.invoice_no }));
export const pick = (key: string, label: string, rows: any[], required = true): Input => ({ key, label, type: 'select', options: options(rows), required });
export const text = (key: string, label: string, required = true, type = 'text'): Input => ({ key, label, required, type });
export function Panel({ title, children }: { title: string; children: ReactNode }) { return <section className="card-pad mb-6"><h2 className="mb-5 text-lg font-semibold">{title}</h2>{children}</section>; }
export function ExportLinks({ module, types }: { module: string; types: string[] }) { return <Panel title="Export operational reports"><div className="grid gap-4 sm:grid-cols-2">{types.map(type => <div key={type}><p className="mb-2 text-sm font-semibold capitalize">{type}</p><div className="flex gap-2">{['csv', 'xlsx', 'pdf'].map(format => <a className="btn-secondary" target="_blank" rel="noreferrer" key={format} href={`/business-reports/${module}/${type}?format=${format}`}>{format.toUpperCase()}</a>)}</div></div>)}</div></Panel>; }
export function RecordForm({ title, action, fields, initial = {}, method = 'post', permission, confirm, submit = 'Save' }: { title: string; action: string; fields: Input[]; initial?: Row; method?: 'post' | 'put' | 'delete'; permission?: string; confirm?: string; submit?: string }) {
    const can = useCan();
    const form = useForm<Row>(Object.fromEntries(fields.map(field => {
        const value = initial[field.key] ?? field.value ?? (field.type === 'checkbox' ? false : field.type === 'rows' ? [] : '');
        return [field.key, field.type === 'date' && typeof value === 'string' ? value.slice(0, 10) : value];
    })));
    if (permission && !can(permission)) return null;
    const render = (field: Input, value: any, change: (value: any) => void, error?: string): ReactNode => <Field key={field.key} label={field.label + (field.required ? ' *' : '')} error={error}>
        {field.type === 'rows' ? <div className="space-y-3">{(value || []).map((row: Row, index: number) => <div className="grid gap-3 rounded-lg border bg-slate-50 p-3 sm:grid-cols-2" key={index}>{field.fields?.map(child => render(child, row[child.key] ?? '', next => change(value.map((entry: Row, i: number) => i === index ? { ...entry, [child.key]: next } : entry)), form.errors[`${field.key}.${index}.${child.key}`]))}<button type="button" className="btn-secondary" onClick={() => change(value.filter((_: Row, i: number) => i !== index))}>Remove row</button></div>)}<button type="button" className="btn-secondary" onClick={() => change([...value, Object.fromEntries((field.fields || []).map(child => [child.key, child.value ?? '']))])}>Add row</button></div>
        : field.type === 'select' ? <select required={field.required} className="input" value={value} onChange={e => change(e.target.value)}><option value="">Select…</option>{field.options?.map(item => <option key={item.value ?? item} value={item.value ?? item}>{item.label ?? item}</option>)}</select>
        : field.type === 'textarea' ? <textarea className="input" rows={3} value={value} onChange={e => change(e.target.value)} />
        : field.type === 'checkbox' ? <input type="checkbox" checked={!!value} onChange={e => change(e.target.checked)} />
        : field.type === 'file' ? <input type="file" className="input" onChange={e => change(e.target.files?.[0] ?? null)} />
        : <input required={field.required} className="input" type={field.type || 'text'} step={field.type === 'number' ? 'any' : undefined} value={value} onChange={e => change(e.target.value)} />}
    </Field>;
    return <Panel title={title}><form onSubmit={e => { e.preventDefault(); if (confirm && !window.confirm(confirm)) return; form[method](action, { preserveScroll: true }); }}>
        {!!Object.keys(form.errors).length && <div role="alert" className="mb-5 rounded-lg bg-red-50 p-3 text-sm text-red-800">{Object.entries(form.errors).map(([key, error]) => <p key={key}>{error}</p>)}</div>}
        <div className="grid gap-5 sm:grid-cols-2">{fields.map(field => <div key={field.key} className={['rows', 'textarea'].includes(field.type || '') ? 'sm:col-span-2' : ''}>{render(field, form.data[field.key], value => form.setData(field.key, value), form.errors[field.key])}</div>)}</div>
        {form.progress && <p role="status" className="mt-4 text-sm">Uploading {form.progress.percentage}%</p>}
        <button disabled={form.processing} className="btn-primary mt-5">{form.processing ? 'Saving…' : submit}</button>
    </form></Panel>;
}
export function DataTable({ data, columns, href, actions }: { data: any; columns: string[]; href?: (row: Row) => string; actions?: (row: Row) => ReactNode }) {
    const rows: Row[] = Array.isArray(data) ? data : data?.data || [];
    return <><div className="table-wrap mb-5"><table className="table"><thead><tr>{columns.map(key => <th key={key}>{key.replace(/_minor$/, ' (GHS)').replace(/_/g, ' ')}</th>)}{(href || actions) && <th>Actions</th>}</tr></thead><tbody>{rows.map((row, index) => <tr key={row.id ?? index}>{columns.map(key => <td key={key}>{format(key, row[key])}</td>)}{(href || actions) && <td><div className="flex flex-wrap gap-2">{href && <Link className="link" href={href(row)}>Open</Link>}{actions?.(row)}</div></td>}</tr>)}</tbody></table>{!rows.length && <Empty>No records found.</Empty>}</div>{data?.links && <Pagination links={data.links} />}</>;
}
export function format(key: string, value: any): ReactNode {
    if (value === null || value === undefined) return '—';
    if (key.endsWith('_minor')) return money(Number(value));
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(value)) return key.endsWith('_date') || key.endsWith('_on') ? value.slice(0, 10) : value.slice(0, 16).replace('T', ' ');
    if (typeof value === 'object') return Array.isArray(value) ? value.map(item => typeof item === 'object' ? item.name ?? item.label ?? '' : item).join(', ') : value.name ?? value.title ?? '—';
    return String(value);
}
export function Details({ data, keys }: { data: Row; keys: string[] }) { return <dl className="mb-6 grid gap-5 rounded-xl border bg-white p-6 sm:grid-cols-3">{keys.map(key => <div key={key}><dt className="mb-1 text-xs capitalize text-slate-500">{key.replace(/_/g, ' ')}</dt><dd className="break-words text-sm">{format(key, data[key])}</dd></div>)}</dl>; }
export function Search({ action, fields, filters = {} }: { action: string; fields: Input[]; filters?: Row }) {
    const [values, setValues] = useState<Row>(filters);
    return <form className="card-pad mb-6 flex flex-wrap items-end gap-3" onSubmit={e => { e.preventDefault(); router.get(action, values, { preserveState: false }); }}>{fields.map(field => <label key={field.key}><span className="label">{field.label}</span>{field.options ? <select className="input" value={values[field.key] || ''} onChange={e => setValues({ ...values, [field.key]: e.target.value })}><option value="">All</option>{field.options.map(option => <option key={option.value ?? option} value={option.value ?? option}>{option.label ?? option}</option>)}</select> : <input type={field.type || 'text'} className="input" value={values[field.key] || ''} onChange={e => setValues({ ...values, [field.key]: e.target.value })} />}</label>)}<button className="btn-primary">Apply filters</button></form>;
}

