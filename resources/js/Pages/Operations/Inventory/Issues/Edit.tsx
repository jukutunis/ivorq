import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption, InventoryItem, InventoryLocation } from '@/Types';

interface ExistingLine { id: string; item_id: string; location_id: string; quantity: number; remarks: string | null; }
interface Issue {
    id: string; issue_number: string; department_id: string | null;
    status: EnumOption; issued_at: string | null; remarks: string | null;
    lines?: ExistingLine[];
}
interface LineForm { item_id: string; location_id: string; quantity: string; remarks: string; }
interface Props { issue: Issue; items: InventoryItem[]; locations: InventoryLocation[]; }

const emptyLine = (): LineForm => ({ item_id: '', location_id: '', quantity: '', remarks: '' });

export default function IssueEdit({ issue, items, locations }: Props) {
    const existingLines: LineForm[] = (issue.lines ?? []).map((l) => ({
        item_id: l.item_id, location_id: l.location_id,
        quantity: String(l.quantity), remarks: l.remarks ?? '',
    }));

    const { data, setData, put, processing, errors } = useForm({
        department_id: issue.department_id ?? '',
        issued_at:     issue.issued_at ?? '',
        remarks:       issue.remarks ?? '',
        lines:         existingLines.length > 0 ? existingLines : [emptyLine()],
    });

    function setLine(i: number, field: keyof LineForm, value: string) {
        const lines = [...data.lines];
        lines[i] = { ...lines[i], [field]: value };
        setData('lines', lines);
    }

    function addLine() { setData('lines', [...data.lines, emptyLine()]); }
    function removeLine(i: number) {
        if (data.lines.length <= 1) return;
        setData('lines', data.lines.filter((_, idx) => idx !== i));
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/inventory/issues/${issue.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href={`/operations/inventory/issues/${issue.id}`} className="text-sm text-gray-500 hover:text-gray-700">← {issue.issue_number}</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Issue</h1>

            <form onSubmit={submit} className="max-w-4xl space-y-6">
                <div className="bg-white rounded-lg shadow p-6 space-y-4">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">Issue Header</h2>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Department ID</label>
                            <input type="text" value={data.department_id}
                                onChange={(e) => setData('department_id', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono" />
                            {errors.department_id && <p className="text-red-600 text-xs mt-1">{errors.department_id}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Issued At</label>
                            <input type="datetime-local" value={data.issued_at}
                                onChange={(e) => setData('issued_at', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.issued_at && <p className="text-red-600 text-xs mt-1">{errors.issued_at}</p>}
                        </div>
                        <div className="col-span-2">
                            <label className="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                            <input type="text" value={data.remarks}
                                onChange={(e) => setData('remarks', e.target.value)} maxLength={500}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.remarks && <p className="text-red-600 text-xs mt-1">{errors.remarks}</p>}
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">Issue Lines</h2>
                        <button type="button" onClick={addLine} className="text-blue-600 hover:text-blue-800 text-sm font-medium">+ Add Line</button>
                    </div>
                    <div className="space-y-3">
                        {data.lines.map((line, i) => (
                            <div key={i} className="grid grid-cols-12 gap-2 items-start p-3 bg-gray-50 rounded border border-gray-200">
                                <div className="col-span-4">
                                    <label className="block text-xs text-gray-500 mb-1">Item *</label>
                                    <select value={line.item_id} onChange={(e) => setLine(i, 'item_id', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500">
                                        <option value="">— Item —</option>
                                        {items.map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}
                                    </select>
                                </div>
                                <div className="col-span-3">
                                    <label className="block text-xs text-gray-500 mb-1">Location *</label>
                                    <select value={line.location_id} onChange={(e) => setLine(i, 'location_id', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500">
                                        <option value="">— Location —</option>
                                        {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                                    </select>
                                </div>
                                <div className="col-span-2">
                                    <label className="block text-xs text-gray-500 mb-1">Qty *</label>
                                    <input type="number" step="0.001" min="0.001" value={line.quantity}
                                        onChange={(e) => setLine(i, 'quantity', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-2">
                                    <label className="block text-xs text-gray-500 mb-1">Remarks</label>
                                    <input type="text" value={line.remarks} onChange={(e) => setLine(i, 'remarks', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-1 pt-5">
                                    <button type="button" onClick={() => removeLine(i)}
                                        className="text-red-400 hover:text-red-600 text-xs font-medium">Remove</button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <button type="submit" disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save Changes'}
                    </button>
                    <Link href={`/operations/inventory/issues/${issue.id}`} className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">Cancel</Link>
                </div>
            </form>
        </AppLayout>
    );
}
