import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Vendor {
    id: string;
    name: string;
}

interface InventoryItem {
    id: string;
    name: string;
    sku: string | null;
}

interface InventoryUnit {
    id: string;
    name: string;
    abbreviation: string | null;
}

interface PurchaseOrderLine {
    id: string;
    purchase_order_id: string;
    inventory_item_id: string;
    ordered_quantity: number;
    received_quantity: number;
    remaining_quantity: number;
    inventory_item: InventoryItem | null;
    unit: InventoryUnit | null;
    unit_id: string | null;
}

interface PurchaseOrder {
    id: string;
    property_id: string;
    vendor_id: string;
    vendor: Vendor | null;
    status: string;
    lines: PurchaseOrderLine[];
    created_at: string;
}

interface StockMovement {
    id: string;
    quantity: number;
    movement_type: string | { value: string };
    occurred_at: string;
}

interface ReceiptLine {
    id: string;
    received_quantity: number;
    inventory_item: InventoryItem | null;
    inventory_location_id: string;
    stock_movement: StockMovement | null;
    stock_movement_id: string | null;
}

interface GoodsReceipt {
    id: string;
    receipt_number: string;
    status: string;
    received_at: string | null;
    purchase_order: PurchaseOrder | null;
    lines: ReceiptLine[];
    received_by_user: { name: string } | null;
    created_at: string;
}

interface Props {
    approvedPos: PurchaseOrder[];
    recentReceipts: GoodsReceipt[];
    confirmationExists: boolean;
}

export default function GoodsReceiptWorkspace({ approvedPos, recentReceipts, confirmationExists }: Props) {
    const [selectedPO, setSelectedPO] = useState<PurchaseOrder | null>(null);
    const [showCreateForm, setShowCreateForm] = useState(false);

    const form = useForm({
        purchase_order_id: '',
        lines: [] as {
            purchase_order_line_id: string;
            inventory_location_id: string;
            inventory_unit_id: string;
            received_quantity: number;
        }[],
    });

    const selectPO = (po: PurchaseOrder) => {
        setSelectedPO(po);
        form.setData('purchase_order_id', po.id);
        form.setData('lines', po.lines.map(l => ({
            purchase_order_line_id: l.id,
            inventory_location_id: '',
            inventory_unit_id: l.unit_id || '',
            received_quantity: 0,
        })));
        setShowCreateForm(true);
    };

    const updateLine = (index: number, field: string, value: string | number) => {
        const newLines = [...form.data.lines];
        (newLines[index] as any)[field] = value;
        form.setData('lines', newLines);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/operations/inventory/goods-receipts', {
            onSuccess: () => {
                setShowCreateForm(false);
                setSelectedPO(null);
            },
        });
    };

    const receivingPos = approvedPos.filter(po =>
        po.status === 'APPROVED' || po.status === 'ISSUED' || po.status === 'PARTIALLY_RECEIVED'
    );

    return (
        <AppLayout>
            <Head title="Goods Receipt" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Goods Receipt</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Controlled receipt posting through Inventory Ledger
                    </p>
                </div>
            </div>

            {/* Sensitive Confirmation Status */}
            <div className={`px-4 py-3 rounded-lg mb-6 text-sm ${confirmationExists ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-amber-50 text-amber-700 border border-amber-200'}`}>
                {confirmationExists
                    ? 'Sensitive confirmation active. You may post receipts.'
                    : (
                        <span>
                            Sensitive confirmation required before posting.{' '}
                            <Link href="/system/sensitive-action-confirmation" className="underline font-medium">
                                Confirm now
                            </Link>
                        </span>
                    )}
            </div>

            {/* Approved POs Ready to Receive */}
            <div className="bg-white rounded-lg shadow overflow-hidden mb-8">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">
                        Approved Purchase Orders Ready to Receive
                    </h2>
                </div>
                {receivingPos.length === 0 ? (
                    <div className="px-6 py-10 text-center text-gray-400 text-sm">
                        No approved purchase orders waiting for receipt.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">PO</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Lines</th>
                                    <th className="text-center px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {receivingPos.map(po => (
                                    <tr key={po.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3 font-medium text-gray-900">{po.id.slice(-8)}</td>
                                        <td className="px-6 py-3 text-gray-600">{po.vendor?.name ?? '—'}</td>
                                        <td className="px-6 py-3">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
                                                {po.status}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3 text-right text-gray-600">{po.lines.length}</td>
                                        <td className="px-6 py-3 text-center">
                                            <button
                                                onClick={() => selectPO(po)}
                                                className="text-xs bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700"
                                            >
                                                Receive
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Create Receipt Form */}
            {showCreateForm && selectedPO && (
                <div className="bg-white rounded-lg shadow overflow-hidden mb-8 border-2 border-blue-200">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">
                            Create Goods Receipt — PO {selectedPO.id.slice(-8)}
                        </h2>
                        <button onClick={() => setShowCreateForm(false)} className="text-gray-400 hover:text-gray-600">
                            Cancel
                        </button>
                    </div>
                    <form onSubmit={handleSubmit} className="p-6">
                        <div className="space-y-4">
                            {form.data.lines.map((line, idx) => {
                                const poLine = selectedPO.lines[idx];
                                return (
                                    <div key={poLine.id} className="border border-gray-200 rounded-lg p-4">
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="font-medium text-sm text-gray-700">
                                                {poLine.inventory_item?.name ?? poLine.inventory_item_id}
                                            </span>
                                            <span className="text-xs text-gray-500">
                                                Remaining: {poLine.remaining_quantity}
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-3 gap-3">
                                            <div>
                                                <label className="block text-xs text-gray-500 mb-1">Location ID</label>
                                                <input
                                                    type="text"
                                                    value={line.inventory_location_id}
                                                    onChange={e => updateLine(idx, 'inventory_location_id', e.target.value)}
                                                    className="w-full border border-gray-300 rounded px-3 py-1.5 text-sm"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs text-gray-500 mb-1">UOM ID</label>
                                                <input
                                                    type="text"
                                                    value={line.inventory_unit_id}
                                                    onChange={e => updateLine(idx, 'inventory_unit_id', e.target.value)}
                                                    className="w-full border border-gray-300 rounded px-3 py-1.5 text-sm"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs text-gray-500 mb-1">Qty Received</label>
                                                <input
                                                    type="number"
                                                    step="0.001"
                                                    min="0.001"
                                                    max={poLine.remaining_quantity}
                                                    value={line.received_quantity || ''}
                                                    onChange={e => updateLine(idx, 'received_quantity', parseFloat(e.target.value) || 0)}
                                                    className="w-full border border-gray-300 rounded px-3 py-1.5 text-sm"
                                                    required
                                                />
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        <div className="mt-6 flex justify-end">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="bg-blue-600 text-white px-6 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-50"
                            >
                                {form.processing ? 'Creating...' : 'Create Draft Receipt'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Recent Posted Receipts */}
            <div className="bg-white rounded-lg shadow overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Recent Posted Goods Receipts</h2>
                </div>
                {recentReceipts.length === 0 ? (
                    <div className="px-6 py-10 text-center text-gray-400 text-sm">
                        No goods receipts posted yet.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">GRN #</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">PO</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Received At</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Receiver</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {recentReceipts.map(gr => (
                                    <tr key={gr.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3 font-medium text-gray-900">
                                            <Link href={`/operations/inventory/goods-receipts/${gr.id}`} className="hover:text-blue-600">
                                                {gr.receipt_number}
                                            </Link>
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">{gr.purchase_order?.id?.slice(-8) ?? '—'}</td>
                                        <td className="px-6 py-3 text-gray-600">{gr.purchase_order?.vendor?.name ?? '—'}</td>
                                        <td className="px-6 py-3">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${
                                                gr.status === 'POSTED' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'
                                            }`}>
                                                {gr.status}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3 text-gray-500 text-xs">
                                            {gr.received_at ? new Date(gr.received_at).toLocaleDateString() : '—'}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">{gr.received_by_user?.name ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
