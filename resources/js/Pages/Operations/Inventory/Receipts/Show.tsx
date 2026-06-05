import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { EnumOption, PageProps } from '@/Types';

interface ReceiptLine {
    id: string; item_id: string; location_id: string;
    quantity: number; unit_cost: number; total_value: number; notes: string | null;
    item?: { id: string; item_code: string; name: string } | null;
    location?: { id: string; location_code: string; name: string } | null;
}
interface Receipt {
    id: string; receipt_number: string; supplier_name: string | null;
    external_reference: string | null; status: EnumOption;
    received_at: string | null; remarks: string | null;
    posted_at: string | null; cancelled_at: string | null;
    created_at: string; updated_at: string;
    posted_by_user?: { id: string; name: string } | null;
    cancelled_by_user?: { id: string; name: string } | null;
    lines?: ReceiptLine[];
}

interface Props { receipt: Receipt; }

const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-600',
    posted:    'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-600',
};

export default function ReceiptShow({ receipt }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);
    const isDraft = receipt.status.value === 'draft';

    function actionPost() {
        if (!confirm('Post this receipt? Stock will be updated and the WAC recalculated.')) return;
        router.post(`/operations/inventory/receipts/${receipt.id}/post`, {}, {
            onSuccess: () => router.reload(),
        });
    }

    function actionCancel() {
        if (!confirm('Cancel this receipt? This cannot be undone.')) return;
        router.post(`/operations/inventory/receipts/${receipt.id}/cancel`, {}, {
            onSuccess: () => router.reload(),
        });
    }

    const totalValue = receipt.lines?.reduce((s, l) => s + l.total_value, 0) ?? 0;

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/receipts" className="text-sm text-gray-500 hover:text-gray-700">← Receipts</Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{receipt.receipt_number}</h1>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${statusColors[receipt.status.value] ?? 'bg-gray-100 text-gray-600'}`}>
                        {receipt.status.label}
                    </span>
                </div>
                <div className="flex gap-2 flex-wrap">
                    {isDraft && can('inventory.receipt.edit') && (
                        <Link href={`/operations/inventory/receipts/${receipt.id}/edit`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Edit</Link>
                    )}
                    {isDraft && can('inventory.receipt.post') && (
                        <button onClick={actionPost}
                            className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700">Post</button>
                    )}
                    {isDraft && can('inventory.receipt.cancel') && (
                        <button onClick={actionCancel}
                            className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm hover:bg-red-100 border border-red-200">Cancel</button>
                    )}
                </div>
            </div>

            {/* Header */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Receipt Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div><p className="text-xs text-gray-500 mb-1">Number</p><p className="text-sm font-mono">{receipt.receipt_number}</p></div>
                    <div><p className="text-xs text-gray-500 mb-1">Supplier</p><p className="text-sm">{receipt.supplier_name ?? '—'}</p></div>
                    <div><p className="text-xs text-gray-500 mb-1">External Ref</p><p className="text-sm font-mono">{receipt.external_reference ?? '—'}</p></div>
                    <div><p className="text-xs text-gray-500 mb-1">Received At</p><p className="text-sm">{receipt.received_at ?? '—'}</p></div>
                    {receipt.posted_at && (
                        <div><p className="text-xs text-gray-500 mb-1">Posted At</p><p className="text-sm">{receipt.posted_at}</p></div>
                    )}
                    {receipt.cancelled_at && (
                        <div><p className="text-xs text-gray-500 mb-1">Cancelled At</p><p className="text-sm">{receipt.cancelled_at}</p></div>
                    )}
                </div>
                {receipt.remarks && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Remarks</p>
                        <p className="text-sm text-gray-700">{receipt.remarks}</p>
                    </div>
                )}
            </div>

            {/* Lines */}
            <div className="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-700">Lines</h2>
                    <span className="text-sm font-mono font-medium text-gray-900">
                        Total: {totalValue.toFixed(2)}
                    </span>
                </div>
                {!receipt.lines || receipt.lines.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">No lines.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Cost</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {receipt.lines.map((l) => (
                                    <tr key={l.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3">
                                            <p className="font-medium text-gray-900">{l.item?.name ?? l.item_id}</p>
                                            <p className="text-xs font-mono text-gray-400">{l.item?.item_code}</p>
                                        </td>
                                        <td className="px-6 py-3 text-gray-600 text-xs">{l.location?.name ?? l.location_id}</td>
                                        <td className="px-6 py-3 text-right font-mono">{l.quantity}</td>
                                        <td className="px-6 py-3 text-right font-mono">{l.unit_cost.toFixed(4)}</td>
                                        <td className="px-6 py-3 text-right font-mono font-medium">{l.total_value.toFixed(2)}</td>
                                        <td className="px-6 py-3 text-gray-500 text-xs">{l.notes ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="text-xs text-gray-400">Created {receipt.created_at} · Updated {receipt.updated_at}</div>
        </AppLayout>
    );
}
