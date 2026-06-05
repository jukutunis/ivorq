import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { InventoryItem, InventoryLocation } from '@/Types';

interface LineForm {
    item_id: string; location_id: string;
    quantity: string; unit_cost: string; notes: string;
}

interface Props { items: InventoryItem[]; locations: InventoryLocation[]; }

const emptyLine = (): LineForm => ({ item_id: '', location_id: '', quantity: '', unit_cost: '', notes: '' });

export default function ReceiptCreate({ items, locations }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        supplier_name:      '',
        external_reference: '',
        received_at:        '',
        remarks:            '',
        lines:              [emptyLine()] as LineForm[],
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

    function submit(e: React.FormEvent) { e.preventDefault(); post('/operations/inventory/receipts'); }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/receipts" className="text-sm text-gray-500 hover:text-gray-700">← Receipts</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Receipt</h1>

            <form onSubmit={submit} className="max-w-4xl space-y-6">
                {/* Header */}
                <div className="bg-white rounded-lg shadow p-6 space-y-4">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">Receipt Header</h2>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Supplier Name</label>
                            <input type="text" value={data.supplier_name}
                                onChange={(e) => setData('supplier_name', e.target.value)}
                                placeholder="e.g. Acme Supplies" maxLength={150}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.supplier_name && <p className="text-red-600 text-xs mt-1">{errors.supplier_name}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">External Reference</label>
                            <input type="text" value={data.external_reference}
                                onChange={(e) => setData('external_reference', e.target.value)}
                                placeholder="e.g. PO-2024-001" maxLength={100}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.external_reference && <p className="text-red-600 text-xs mt-1">{errors.external_reference}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Received At</label>
                            <input type="datetime-local" value={data.received_at}
                                onChange={(e) => setData('received_at', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.received_at && <p className="text-red-600 text-xs mt-1">{errors.received_at}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                            <input type="text" value={data.remarks}
                                onChange={(e) => setData('remarks', e.target.value)}
                                placeholder="Optional remarks" maxLength={500}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.remarks && <p className="text-red-600 text-xs mt-1">{errors.remarks}</p>}
                        </div>
                    </div>
                </div>

                {/* Lines */}
                <div className="bg-white rounded-lg shadow p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">Receipt Lines</h2>
                        <button type="button" onClick={addLine}
                            className="text-blue-600 hover:text-blue-800 text-sm font-medium">+ Add Line</button>
                    </div>
                    <div className="space-y-3">
                        {data.lines.map((line, i) => (
                            <div key={i} className="grid grid-cols-12 gap-2 items-start p-3 bg-gray-50 rounded border border-gray-200">
                                <div className="col-span-3">
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
                                        placeholder="0.00"
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-2">
                                    <label className="block text-xs text-gray-500 mb-1">Unit Cost *</label>
                                    <input type="number" step="0.0001" min="0" value={line.unit_cost}
                                        onChange={(e) => setLine(i, 'unit_cost', e.target.value)}
                                        placeholder="0.0000"
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-1">
                                    <label className="block text-xs text-gray-500 mb-1">Notes</label>
                                    <input type="text" value={line.notes}
                                        onChange={(e) => setLine(i, 'notes', e.target.value)}
                                        className="border border-gray-300 rounded px-2 py-1.5 text-xs w-full focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                </div>
                                <div className="col-span-1 pt-5">
                                    <button type="button" onClick={() => removeLine(i)}
                                        className="text-red-400 hover:text-red-600 text-xs font-medium">Remove</button>
                                </div>
                            </div>
                        ))}
                    </div>
                    {(errors as any)['lines'] && <p className="text-red-600 text-xs mt-2">{(errors as any)['lines']}</p>}
                </div>

                <div className="flex items-center gap-3">
                    <button type="submit" disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60">
                        {processing ? 'Creating…' : 'Create Receipt'}
                    </button>
                    <Link href="/operations/inventory/receipts" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">Cancel</Link>
                </div>
            </form>
        </AppLayout>
    );
}
