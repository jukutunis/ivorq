import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption, InventoryItem, InventoryLocation } from '@/Types';

interface ExistingLine { id: string; item_id: string; quantity_system: number; quantity_actual: number; unit_cost: number | null; notes: string | null; }
interface Adjustment {
    id: string; adjustment_number: string; location_id: string;
    adjustment_type: EnumOption; reason: string | null;
    lines?: ExistingLine[];
}

interface LineForm { item_id: string; quantity_system: string; quantity_actual: string; unit_cost: string; notes: string; }
interface Props { adjustment: Adjustment; adjustment_types: EnumOption[]; items: InventoryItem[]; locations: InventoryLocation[]; }

const emptyLine = (): LineForm => ({ item_id: '', quantity_system: '', quantity_actual: '', unit_cost: '', notes: '' });

export default function AdjustmentEdit({ adjustment, adjustment_types, items, locations }: Props) {
    const existingLines: LineForm[] = (adjustment.lines ?? []).map((l) => ({
        item_id: l.item_id, quantity_system: String(l.quantity_system), quantity_actual: String(l.quantity_actual),
        unit_cost: l.unit_cost != null ? String(l.unit_cost) : '', notes: l.notes ?? '',
    }));

    const { data, setData, put, processing, errors } = useForm({
        location_id:     adjustment.location_id,
        adjustment_type: adjustment.adjustment_type.value,
        reason:          adjustment.reason ?? '',
        lines:           existingLines.length > 0 ? existingLines : [emptyLine()],
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
        put(`/operations/inventory/adjustments/${adjustment.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href={`/operations/inventory/adjustments/${adjustment.id}`} className="text-sm text-gray-500 hover:text-gray-700">← {adjustment.adjustment_number}</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Adjustment</h1>

            <form onSubmit={submit} className="max-w-5xl space-y-6">
                <div className="bg-white rounded-lg shadow p-6 space-y-4">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">Adjustment Header</h2>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Location <span className="text-red-500">*</span></label>
                            <select value={data.location_id}
                                onChange={(e) => setData('location_id', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">— Select location —</option>
                                {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                            </select>
                            {errors.location_id && <p className="text-red-600 text-xs mt-1">{errors.location_id}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Type <span className="text-red-500">*</span></label>
                            <select value={data.adjustment_type}
                                onChange={(e) => setData('adjustment_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">— Select type —</option>
                                {adjustment_types.map((t) => <option key={String(t.value)} value={String(t.value)}>{t.label}</option>)}
                            </select>
                            {errors.adjustment_type && <p className="text-red-600 text-xs mt-1">{errors.adjustment_type}</p>}
                        </div>
                        <div className="col-span-2">
                            <label className="block text-sm font-medium text-gray-700 mb-1">Reason <span className="text-red-500">*</span></label>
                            <input type="text" value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)} maxLength={255}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.reason && <p className="text-red-600 text-xs mt-1">{errors.reason}</p>}
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">Adjustment Lines</h2>
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
                                <div className="col-span-2">
                                    <label className="block text-xs text-gray-500 mb-1">System Qty *</label>
                                    <input type="number" step="0.001" value={line.quantity_system}
                                        onChange={(e) => setLine(i, 'quantity_system', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-2">
                                    <label className="block text-xs text-gray-500 mb-1">Actual Qty *</label>
                                    <input type="number" step="0.001" min="0" value={line.quantity_actual}
                                        onChange={(e) => setLine(i, 'quantity_actual', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-2">
                                    <label className="block text-xs text-gray-500 mb-1">Unit Cost</label>
                                    <input type="number" step="0.0001" min="0" value={line.unit_cost}
                                        onChange={(e) => setLine(i, 'unit_cost', e.target.value)}
                                        placeholder="Optional"
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-1">
                                    <label className="block text-xs text-gray-500 mb-1">Notes</label>
                                    <input type="text" value={line.notes} onChange={(e) => setLine(i, 'notes', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-1 pt-5 flex justify-end">
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
                    <Link href={`/operations/inventory/adjustments/${adjustment.id}`} className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">Cancel</Link>
                </div>
            </form>
        </AppLayout>
    );
}
