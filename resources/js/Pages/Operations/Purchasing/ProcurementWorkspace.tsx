import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, ReactNode, useMemo, useState } from 'react';
import { ChevronRight, CircleAlert, PackageCheck, Plus, Search, X } from 'lucide-react';

type Ref = { id: string; name: string; code?: string | null; category_code?: string | null; sku?: string | null };
type Line = { id?: string; inventory_item_id: string; unit_id: string; quantity?: string; ordered_quantity?: string; estimated_unit_cost?: string; unit_cost?: string; description?: string; inventory_item?: Ref | null; unit?: Ref | null };
type Vendor = { id: string; vendor_category_id: string; vendor_code: string; name: string; tax_id?: string | null; default_currency_code: string; is_active: boolean; is_approved: boolean; category?: Ref | null };
type PR = { id: string; request_no: string; department_id: string; requester_id: string; required_date: string; estimated_total: string; status: string; remarks?: string | null; department?: Ref | null; requester?: { name: string } | null; lines: Line[]; approved_by?: string | null; rejected_by?: string | null; rejection_reason?: string | null };
type PO = { id: string; po_no: string; vendor_id: string; purchase_request_id: string; expected_delivery_date: string; total_amount: string; status: string; remarks?: string | null; vendor?: Vendor | null; purchase_request?: PR | null; lines: Line[]; receiving_url?: string | null; approved_by?: string | null; rejected_by?: string | null; rejection_reason?: string | null; issued_by?: string | null };

interface Props {
    context: { property_name: string; currency_code: string };
    vendors: Vendor[];
    purchaseRequests: PR[];
    purchaseOrders: PO[];
    approvalQueue: { purchase_requests: PR[]; purchase_orders: PO[] };
    reference: { categories: Ref[]; departments: Ref[]; items: Ref[]; units: Ref[] };
    abilities: Record<string, boolean>;
}

const tabs = ['Vendors', 'Requisitions', 'Approvals', 'Purchase orders', 'Receiving handoff'] as const;
type Tab = typeof tabs[number];

function Badge({ value }: { value: string }) {
    const tone = value === 'APPROVED' || value === 'ISSUED' || value === 'Eligible'
        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
        : value === 'REJECTED' || value === 'CANCELLED'
            ? 'bg-rose-50 text-rose-700 border-rose-200'
            : value === 'PENDING_REVIEW'
                ? 'bg-amber-50 text-amber-700 border-amber-200'
                : 'bg-slate-50 text-slate-700 border-slate-200';
    return <span className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold tracking-wide ${tone}`}>{value.replaceAll('_', ' ')}</span>;
}

function Drawer({ title, children, onClose }: { title: string; children: ReactNode; onClose: () => void }) {
    return <div className="fixed inset-0 z-40 flex justify-end bg-slate-950/25" onMouseDown={onClose}>
        <section className="h-full w-full max-w-xl overflow-y-auto bg-white shadow-2xl" onMouseDown={event => event.stopPropagation()}>
            <header className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-5">
                <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
                <button onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Close"><X size={18} /></button>
            </header>
            <div className="p-6">{children}</div>
        </section>
    </div>;
}

function ActionDialog({ title, description, requireReason, onCancel, onConfirm }: { title: string; description: string; requireReason?: boolean; onCancel: () => void; onConfirm: (reason: string) => void }) {
    const [reason, setReason] = useState('');
    return <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/35 p-4">
        <section className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-700"><CircleAlert size={20} /></div>
            <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
            <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
            {requireReason && <textarea value={reason} onChange={event => setReason(event.target.value)} placeholder="Record the operational reason" className="mt-4 min-h-24 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none" />}
            <div className="mt-6 flex justify-end gap-3">
                <button onClick={onCancel} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</button>
                <button disabled={requireReason && !reason.trim()} onClick={() => onConfirm(reason)} className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Confirm</button>
            </div>
        </section>
    </div>;
}

const field = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100';
const label = 'mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500';

export default function ProcurementWorkspace(props: Props) {
    const [tab, setTab] = useState<Tab>('Vendors');
    const [query, setQuery] = useState('');
    const [drawer, setDrawer] = useState<{ type: 'vendor' | 'pr' | 'po'; record?: Vendor | PR | PO } | null>(null);
    const [dialog, setDialog] = useState<{ title: string; description: string; reason?: boolean; path: string; payload?: Record<string, unknown> } | null>(null);
    const [expanded, setExpanded] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const term = query.trim().toLowerCase();
    const vendors = useMemo(() => props.vendors.filter(v => !term || `${v.vendor_code} ${v.name} ${v.category?.name ?? ''}`.toLowerCase().includes(term)), [props.vendors, term]);
    const prs = useMemo(() => props.purchaseRequests.filter(pr => !term || `${pr.request_no} ${pr.status} ${pr.department?.name ?? ''}`.toLowerCase().includes(term)), [props.purchaseRequests, term]);
    const pos = useMemo(() => props.purchaseOrders.filter(po => !term || `${po.po_no} ${po.status} ${po.vendor?.name ?? ''}`.toLowerCase().includes(term)), [props.purchaseOrders, term]);

    const mutate = async (method: 'post' | 'put', path: string, payload: Record<string, unknown> = {}) => {
        setBusy(true); setError(null);
        try {
            await axios({ method, url: path, data: payload });
            setDrawer(null); setDialog(null);
            router.reload();
        } catch (caught: any) {
            const errors = caught?.response?.data?.errors;
            setError(errors ? Object.values(errors).flat().join(' ') : caught?.response?.data?.message ?? 'The action could not be completed.');
        } finally { setBusy(false); }
    };

    const confirm = (config: typeof dialog) => config && setDialog(config);
    const action = (path: string, title: string, description: string, reason = false, payload: Record<string, unknown> = {}) => confirm({ path, title, description, reason, payload });

    return <AppLayout>
        <Head title="Procurement control plane" />
        <div className="mx-auto max-w-7xl space-y-6">
            <header className="overflow-hidden rounded-3xl bg-slate-950 px-7 py-7 text-white shadow-sm">
                <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.22em] text-blue-300">Operational procurement</p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Requisition to issued PO</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-300">{props.context.property_name} · {props.context.currency_code} · Company-owned vendor eligibility with Property-controlled demand.</p>
                    </div>
                    <div className="grid grid-cols-3 gap-3 text-center">
                        <Metric value={props.vendors.filter(v => v.is_active && v.is_approved).length} label="Eligible vendors" />
                        <Metric value={props.approvalQueue.purchase_requests.length + props.approvalQueue.purchase_orders.length} label="Awaiting decision" />
                        <Metric value={props.purchaseOrders.filter(po => ['APPROVED', 'ISSUED'].includes(po.status)).length} label="Receiving eligible" />
                    </div>
                </div>
            </header>

            {error && <div className="flex items-start justify-between rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><span>{error}</span><button onClick={() => setError(null)}><X size={16} /></button></div>}

            <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="flex flex-col gap-4 border-b border-slate-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <nav className="flex gap-1 overflow-x-auto">
                        {tabs.map(name => <button key={name} onClick={() => setTab(name)} className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium ${tab === name ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>{name}</button>)}
                    </nav>
                    <div className="flex items-center gap-3">
                        <div className="relative"><Search size={15} className="absolute left-3 top-3 text-slate-400" /><input value={query} onChange={event => setQuery(event.target.value)} placeholder="Search current view" className="w-64 rounded-xl border border-slate-300 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-blue-500" /></div>
                        {tab === 'Vendors' && props.abilities.vendor_create && <Primary onClick={() => setDrawer({ type: 'vendor' })}>New vendor</Primary>}
                        {tab === 'Requisitions' && props.abilities.request_create && <Primary onClick={() => setDrawer({ type: 'pr' })}>New requisition</Primary>}
                    </div>
                </div>

                {tab === 'Vendors' && <VendorTable rows={vendors} abilities={props.abilities} expanded={expanded} setExpanded={setExpanded} onEdit={(record: Vendor) => setDrawer({ type: 'vendor', record })} onApprove={(record: Vendor) => action(`/purchasing/vendors/${record.id}/approve`, `Approve ${record.name}?`, 'Approval makes this Company-owned Vendor eligible for controlled PO conversion.')} />}
                {tab === 'Requisitions' && <PRTable rows={prs} abilities={props.abilities} expanded={expanded} setExpanded={setExpanded} onEdit={(record: PR) => setDrawer({ type: 'pr', record })} onConvert={(record: PR) => setDrawer({ type: 'po', record })} onAction={action} />}
                {tab === 'Approvals' && <ApprovalQueue {...props} onAction={action} />}
                {tab === 'Purchase orders' && <POTable rows={pos} abilities={props.abilities} expanded={expanded} setExpanded={setExpanded} onEdit={(record: PO) => setDrawer({ type: 'po', record })} onAction={action} />}
                {tab === 'Receiving handoff' && <Receiving rows={pos.filter(po => po.receiving_url)} />}
            </section>
        </div>

        {drawer?.type === 'vendor' && <VendorForm record={drawer.record as Vendor | undefined} reference={props.reference} busy={busy} onClose={() => setDrawer(null)} onSave={(method: 'post' | 'put', path: string, payload: Record<string, unknown>) => mutate(method, path, payload)} />}
        {drawer?.type === 'pr' && <PRForm record={drawer.record as PR | undefined} reference={props.reference} busy={busy} onClose={() => setDrawer(null)} onSave={(method: 'post' | 'put', path: string, payload: Record<string, unknown>) => mutate(method, path, payload)} />}
        {drawer?.type === 'po' && <POForm record={drawer.record as PR | PO | undefined} vendors={props.vendors.filter(v => v.is_active && v.is_approved)} busy={busy} onClose={() => setDrawer(null)} onSave={(method: 'post' | 'put', path: string, payload: Record<string, unknown>) => mutate(method, path, payload)} />}
        {dialog && <ActionDialog title={dialog.title} description={dialog.description} requireReason={dialog.reason} onCancel={() => setDialog(null)} onConfirm={reason => mutate('post', dialog.path, { ...dialog.payload, ...(dialog.reason ? { reason } : {}) })} />}
    </AppLayout>;
}

function Metric({ value, label: text }: { value: number; label: string }) { return <div className="rounded-2xl bg-white/10 px-4 py-3"><div className="text-xl font-semibold">{value}</div><div className="text-[10px] uppercase tracking-wide text-slate-300">{text}</div></div>; }
function Primary({ children, onClick }: { children: ReactNode; onClick: () => void }) { return <button onClick={onClick} className="inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"><Plus size={15} />{children}</button>; }
function Empty({ text }: { text: string }) { return <div className="px-6 py-16 text-center text-sm text-slate-500">{text}</div>; }
function ActionButton({ children, onClick }: { children: ReactNode; onClick: () => void }) { return <button onClick={onClick} className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:border-slate-400 hover:bg-slate-50">{children}</button>; }

function VendorTable({ rows, abilities, expanded, setExpanded, onEdit, onApprove }: any) {
    if (!rows.length) return <Empty text="No Vendors match this view." />;
    return <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-5 py-3">Vendor</th><th>Category</th><th>Eligibility</th><th className="px-5 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-100">{rows.map((v: Vendor) => <tr key={v.id} className="hover:bg-slate-50/70"><td className="px-5 py-4"><button onClick={() => setExpanded(expanded === v.id ? null : v.id)} className="text-left"><div className="font-semibold text-slate-950">{v.name}</div><div className="text-xs text-slate-500">{v.vendor_code}</div></button></td><td>{v.category?.name ?? 'Property metadata unavailable'}</td><td><div className="flex gap-2"><Badge value={v.is_active ? 'ACTIVE' : 'INACTIVE'} /><Badge value={v.is_approved ? 'APPROVED' : 'PENDING'} /></div></td><td className="px-5"><div className="flex justify-end gap-2">{abilities.vendor_edit && <ActionButton onClick={() => onEdit(v)}>Edit</ActionButton>}{abilities.vendor_approve && !v.is_approved && <ActionButton onClick={() => onApprove(v)}>Approve</ActionButton>}</div></td></tr>)}</tbody></table></div>;
}

function PRTable({ rows, abilities, expanded, setExpanded, onEdit, onConvert, onAction }: any) {
    if (!rows.length) return <Empty text="No Purchase Requisitions match this view." />;
    return <div className="divide-y divide-slate-100">{rows.map((pr: PR) => <div key={pr.id} className="px-5 py-4"><div className="flex flex-col justify-between gap-3 md:flex-row md:items-center"><button onClick={() => setExpanded(expanded === pr.id ? null : pr.id)} className="flex items-center gap-3 text-left"><ChevronRight size={16} className={expanded === pr.id ? 'rotate-90' : ''} /><div><div className="font-semibold text-slate-950">{pr.request_no}</div><div className="text-xs text-slate-500">{pr.department?.name ?? 'Department'} · needed {pr.required_date}</div></div></button><div className="flex flex-wrap items-center justify-end gap-2"><span className="mr-2 font-semibold text-slate-800">{Number(pr.estimated_total).toLocaleString()}</span><Badge value={pr.status} />{pr.status === 'DRAFT' && abilities.request_edit && <><ActionButton onClick={() => onEdit(pr)}>Edit</ActionButton><ActionButton onClick={() => onAction(`/purchasing/purchase-requests/${pr.id}/submit`, `Submit ${pr.request_no}?`, 'Submission freezes commercial content and starts the durable approval workflow.')}>Submit</ActionButton></>}{pr.status === 'APPROVED' && abilities.order_create && <ActionButton onClick={() => onConvert(pr)}>Convert to PO</ActionButton>}{['DRAFT', 'PENDING_REVIEW'].includes(pr.status) && abilities.request_cancel && <ActionButton onClick={() => onAction(`/purchasing/purchase-requests/${pr.id}/cancel`, `Cancel ${pr.request_no}?`, 'Cancellation is terminal and preserves prior approval evidence.', true)}>Cancel</ActionButton>}</div></div>{expanded === pr.id && <div className="ml-7 mt-4 grid gap-2 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">{pr.lines.map((line, index) => <div key={line.id ?? index} className="flex justify-between"><span>{line.inventory_item?.name ?? line.description}</span><span>{line.quantity} {line.unit?.code} × {line.estimated_unit_cost}</span></div>)}{pr.rejection_reason && <p className="mt-2 text-rose-700">Rejected: {pr.rejection_reason}</p>}</div>}</div>)}</div>;
}

function ApprovalQueue({ approvalQueue, abilities, onAction }: Props & { onAction: any }) {
    const empty = !approvalQueue.purchase_requests.length && !approvalQueue.purchase_orders.length;
    if (empty) return <Empty text="No Procurement decisions are waiting." />;
    return <div className="grid gap-5 p-5 lg:grid-cols-2"><Queue title="Purchase Requisitions" rows={approvalQueue.purchase_requests} render={(pr: PR) => <><div><div className="font-semibold">{pr.request_no}</div><div className="text-xs text-slate-500">{pr.requester?.name} · {pr.estimated_total}</div></div>{abilities.request_approve && <div className="flex gap-2"><ActionButton onClick={() => onAction(`/purchasing/purchase-requests/${pr.id}/approve`, `Approve ${pr.request_no}?`, 'Your identity becomes durable approval evidence.')}>Approve</ActionButton><ActionButton onClick={() => onAction(`/purchasing/purchase-requests/${pr.id}/reject`, `Reject ${pr.request_no}?`, 'Record the reason for the immutable rejection decision.', true, { remarks: undefined })}>Reject</ActionButton></div>}</>} /><Queue title="Purchase Orders" rows={approvalQueue.purchase_orders} render={(po: PO) => <><div><div className="font-semibold">{po.po_no}</div><div className="text-xs text-slate-500">{po.vendor?.name} · {po.total_amount}</div></div>{abilities.order_approve && <div className="flex gap-2"><ActionButton onClick={() => onAction(`/purchasing/purchase-orders/${po.id}/approve`, `Approve ${po.po_no}?`, 'Approval freezes the controlled commercial commitment.')}>Approve</ActionButton><ActionButton onClick={() => onAction(`/purchasing/purchase-orders/${po.id}/reject`, `Reject ${po.po_no}?`, 'Record the reason for the immutable rejection decision.', true)}>Reject</ActionButton></div>}</>} /></div>;
}

function Queue({ title, rows, render }: { title: string; rows: any[]; render: (row: any) => ReactNode }) { return <section className="rounded-2xl border border-slate-200"><h3 className="border-b border-slate-200 px-4 py-3 text-sm font-semibold">{title}</h3><div className="divide-y divide-slate-100">{rows.length ? rows.map(row => <div key={row.id} className="flex items-center justify-between gap-3 p-4">{render(row)}</div>) : <div className="p-6 text-sm text-slate-500">Queue clear.</div>}</div></section>; }

function POTable({ rows, abilities, expanded, setExpanded, onEdit, onAction }: any) {
    if (!rows.length) return <Empty text="No Purchase Orders match this view." />;
    return <div className="divide-y divide-slate-100">{rows.map((po: PO) => <div key={po.id} className="px-5 py-4"><div className="flex flex-col justify-between gap-3 md:flex-row md:items-center"><button onClick={() => setExpanded(expanded === po.id ? null : po.id)} className="flex items-center gap-3 text-left"><ChevronRight size={16} className={expanded === po.id ? 'rotate-90' : ''} /><div><div className="font-semibold text-slate-950">{po.po_no}</div><div className="text-xs text-slate-500">{po.vendor?.name} · from {po.purchase_request?.request_no}</div></div></button><div className="flex flex-wrap items-center justify-end gap-2"><span className="mr-2 font-semibold">{Number(po.total_amount).toLocaleString()}</span><Badge value={po.status} />{po.status === 'DRAFT' && abilities.order_edit && <><ActionButton onClick={() => onEdit(po)}>Edit</ActionButton><ActionButton onClick={() => onAction(`/purchasing/purchase-orders/${po.id}/submit`, `Submit ${po.po_no}?`, 'Submission freezes commercial content and starts approval.')}>Submit</ActionButton></>}{po.status === 'APPROVED' && abilities.order_issue && <ActionButton onClick={() => onAction(`/purchasing/purchase-orders/${po.id}/issue`, `Issue ${po.po_no}?`, 'Issue is replay-safe and creates one operational Task and Notification.', false, { idempotency_key: crypto.randomUUID() })}>Issue</ActionButton>}{['DRAFT', 'PENDING_REVIEW', 'APPROVED', 'ISSUED'].includes(po.status) && abilities.order_cancel && <ActionButton onClick={() => onAction(`/purchasing/purchase-orders/${po.id}/cancel`, `Cancel ${po.po_no}?`, 'Cancellation fails closed if receiving has begun.', true)}>Cancel</ActionButton>}</div></div>{expanded === po.id && <div className="ml-7 mt-4 rounded-xl bg-slate-50 p-4">{po.lines.map((line, index) => <div key={line.id ?? index} className="flex justify-between py-1 text-sm text-slate-600"><span>{line.inventory_item?.name ?? line.description}</span><span>{line.ordered_quantity} {line.unit?.code} × {line.unit_cost}</span></div>)}{po.rejection_reason && <p className="mt-2 text-sm text-rose-700">Rejected: {po.rejection_reason}</p>}</div>}</div>)}</div>;
}

function Receiving({ rows }: { rows: PO[] }) { if (!rows.length) return <Empty text="No Approved or Issued POs are eligible for controlled Goods Receipt." />; return <div className="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">{rows.map(po => <article key={po.id} className="rounded-2xl border border-slate-200 p-5"><div className="flex items-start justify-between"><PackageCheck className="text-emerald-600" size={22} /><Badge value="Eligible" /></div><h3 className="mt-5 font-semibold text-slate-950">{po.po_no}</h3><p className="mt-1 text-sm text-slate-500">{po.vendor?.name} · {po.lines.length} line(s)</p><Link href={po.receiving_url!} className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-blue-700">Open controlled Goods Receipt <ChevronRight size={15} /></Link></article>)}</div>; }

function VendorForm({ record, reference, busy, onClose, onSave }: any) {
    const [data, setData] = useState({ vendor_category_id: record?.vendor_category_id ?? '', vendor_code: record?.vendor_code ?? '', name: record?.name ?? '', tax_id: record?.tax_id ?? '', default_currency_code: record?.default_currency_code ?? 'IDR', is_active: record?.is_active ?? true });
    const submit = (event: FormEvent) => { event.preventDefault(); onSave(record ? 'put' : 'post', record ? `/purchasing/vendors/${record.id}` : '/purchasing/vendors', data); };
    return <Drawer title={record ? 'Edit Vendor' : 'Register Company Vendor'} onClose={onClose}><form onSubmit={submit} className="space-y-5"><Select label="Property category" value={data.vendor_category_id} onChange={(value: string) => setData({ ...data, vendor_category_id: value })} options={reference.categories} /><Input label="Vendor code" value={data.vendor_code} onChange={(value: string) => setData({ ...data, vendor_code: value })} /><Input label="Legal / trading name" value={data.name} onChange={(value: string) => setData({ ...data, name: value })} /><Input label="Tax ID" value={data.tax_id} onChange={(value: string) => setData({ ...data, tax_id: value })} required={false} /><p className="rounded-xl bg-blue-50 p-3 text-xs leading-5 text-blue-800">Company ownership is resolved by the server from the active Property. It cannot be selected here.</p><button disabled={busy} className="w-full rounded-xl bg-blue-600 py-3 text-sm font-semibold text-white disabled:opacity-50">{record ? 'Save Draft Changes' : 'Create Unapproved Vendor'}</button></form></Drawer>;
}

function PRForm({ record, reference, busy, onClose, onSave }: any) {
    const [data, setData] = useState<any>({ department_id: record?.department_id ?? '', required_date: record?.required_date ?? '', remarks: record?.remarks ?? '', idempotency_key: record ? undefined : crypto.randomUUID(), lines: (record?.lines ?? [{ inventory_item_id: '', unit_id: '', quantity: '1', estimated_unit_cost: '0' }]).map((line: Line) => ({ inventory_item_id: line.inventory_item_id, unit_id: line.unit_id, quantity: line.quantity ?? '1', estimated_unit_cost: line.estimated_unit_cost ?? '0', remarks: '' })) });
    const updateLine = (index: number, key: string, value: string) => setData({ ...data, lines: data.lines.map((line: any, i: number) => i === index ? { ...line, [key]: value } : line) });
    const submit = (event: FormEvent) => { event.preventDefault(); const payload: any = { ...data }; if (record) delete payload.idempotency_key; onSave(record ? 'put' : 'post', record ? `/purchasing/purchase-requests/${record.id}` : '/purchasing/purchase-requests', payload); };
    return <Drawer title={record ? `Edit ${record.request_no}` : 'New Purchase Requisition'} onClose={onClose}><form onSubmit={submit} className="space-y-5"><Select label="Department" value={data.department_id} onChange={(value: string) => setData({ ...data, department_id: value })} options={reference.departments} /><Input label="Required date" type="date" value={data.required_date} onChange={(value: string) => setData({ ...data, required_date: value })} /><Input label="Operational remarks" value={data.remarks} onChange={(value: string) => setData({ ...data, remarks: value })} required={false} /><div><div className="mb-3 flex items-center justify-between"><h3 className="text-sm font-semibold">Requested items</h3><button type="button" onClick={() => setData({ ...data, lines: [...data.lines, { inventory_item_id: '', unit_id: '', quantity: '1', estimated_unit_cost: '0', remarks: '' }] })} className="text-xs font-semibold text-blue-700">Add line</button></div><div className="space-y-3">{data.lines.map((line: any, index: number) => <div key={index} className="rounded-2xl border border-slate-200 p-4"><div className="grid gap-3 md:grid-cols-2"><Select label="Inventory item" value={line.inventory_item_id} onChange={(value: string) => updateLine(index, 'inventory_item_id', value)} options={reference.items} /><Select label="Canonical UOM" value={line.unit_id} onChange={(value: string) => updateLine(index, 'unit_id', value)} options={reference.units} /><Input label="Quantity" type="number" value={line.quantity} onChange={(value: string) => updateLine(index, 'quantity', value)} /><Input label="Estimated unit cost" type="number" value={line.estimated_unit_cost} onChange={(value: string) => updateLine(index, 'estimated_unit_cost', value)} /></div></div>)}</div></div><p className="rounded-xl bg-blue-50 p-3 text-xs leading-5 text-blue-800">Requester, Property, Company, PR number, item description, currency and exchange rate are server-owned.</p><button disabled={busy} className="w-full rounded-xl bg-blue-600 py-3 text-sm font-semibold text-white disabled:opacity-50">{record ? 'Save Draft' : 'Create Draft Requisition'}</button></form></Drawer>;
}

function POForm({ record, vendors, busy, onClose, onSave }: any) {
    const converting = record && 'request_no' in record;
    const [data, setData] = useState<any>(converting ? { purchase_request_id: record.id, vendor_id: '', expected_delivery_date: '', remarks: '' } : { expected_delivery_date: record?.expected_delivery_date ?? '', remarks: record?.remarks ?? '', lines: (record?.lines ?? []).map((line: Line) => ({ id: line.id, ordered_quantity: line.ordered_quantity, unit_cost: line.unit_cost })) });
    const submit = (event: FormEvent) => { event.preventDefault(); onSave(converting ? 'post' : 'put', converting ? '/purchasing/purchase-orders' : `/purchasing/purchase-orders/${record.id}`, data); };
    return <Drawer title={converting ? `Convert ${record.request_no} to PO` : `Edit ${record.po_no}`} onClose={onClose}><form onSubmit={submit} className="space-y-5">{converting && <Select label="Eligible Company Vendor" value={data.vendor_id} onChange={(value: string) => setData({ ...data, vendor_id: value })} options={vendors} />}<Input label="Expected delivery" type="date" value={data.expected_delivery_date} onChange={(value: string) => setData({ ...data, expected_delivery_date: value })} /><Input label="Remarks" value={data.remarks} onChange={(value: string) => setData({ ...data, remarks: value })} required={false} />{!converting && data.lines?.map((line: any, index: number) => <div key={line.id} className="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 p-4"><Input label="Ordered quantity" type="number" value={line.ordered_quantity} onChange={(value: string) => setData({ ...data, lines: data.lines.map((row: any, i: number) => i === index ? { ...row, ordered_quantity: value } : row) })} /><Input label="Unit cost" type="number" value={line.unit_cost} onChange={(value: string) => setData({ ...data, lines: data.lines.map((row: any, i: number) => i === index ? { ...row, unit_cost: value } : row) })} /></div>)}<p className="rounded-xl bg-blue-50 p-3 text-xs leading-5 text-blue-800">PO identity, Vendor Company eligibility, currency and all source lineage are revalidated server-side.</p><button disabled={busy} className="w-full rounded-xl bg-blue-600 py-3 text-sm font-semibold text-white disabled:opacity-50">{converting ? 'Create Draft PO' : 'Save Draft PO'}</button></form></Drawer>;
}

function Input({ label: text, value, onChange, type = 'text', required = true }: any) { return <label><span className={label}>{text}</span><input type={type} required={required} step={type === 'number' ? '0.001' : undefined} min={type === 'number' ? '0' : undefined} value={value ?? ''} onChange={event => onChange(event.target.value)} className={field} /></label>; }
function Select({ label: text, value, onChange, options }: any) { return <label><span className={label}>{text}</span><select required value={value} onChange={event => onChange(event.target.value)} className={field}><option value="">Select…</option>{options.map((option: any) => <option key={option.id} value={option.id}>{option.name} {option.code ?? option.sku ?? option.category_code ? `· ${option.code ?? option.sku ?? option.category_code}` : ''}</option>)}</select></label>; }
