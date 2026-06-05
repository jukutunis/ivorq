import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { EnumOption, PageProps } from '@/Types';
import { useState } from 'react';

interface AdjustmentLine {
    id: string; item_id: string; quantity_system: number; quantity_actual: number; quantity_variance: number;
    unit_cost: number | null; notes: string | null;
    item?: { id: string; item_code: string; name: string } | null;
}
interface Adjustment {
    id: string; adjustment_number: string; location_id: string;
    adjustment_type: EnumOption; status: EnumOption; reason: string | null; rejection_reason: string | null;
    submitted_at: string | null; approved_at: string | null; rejected_at: string | null;
    created_at: string; updated_at: string;
    location?: { id: string; name: string } | null;
    lines?: AdjustmentLine[];
}

interface Props { adjustment: Adjustment; }

const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-600',
    submitted: 'bg-blue-100 text-blue-700',
    approved:  'bg-green-100 text-green-700',
    rejected:  'bg-red-100 text-red-600',
    cancelled: 'bg-gray-200 text-gray-800',
};

const typeColors: Record<string, string> = {
    physical_count: 'bg-indigo-50 text-indigo-700 border border-indigo-200',
    damage:         'bg-orange-50 text-orange-700 border border-orange-200',
    loss:           'bg-red-50 text-red-700 border border-red-200',
    other:          'bg-gray-50 text-gray-700 border border-gray-200',
};

export default function AdjustmentShow({ adjustment }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);
    
    const isDraft = adjustment.status.value === 'draft';
    const isSubmitted = adjustment.status.value === 'submitted';

    const [rejecting, setRejecting] = useState(false);
    const [rejectionReason, setRejectionReason] = useState('');

    function actionSubmit() {
        if (!confirm('Submit this adjustment for approval?')) return;
        router.post(`/operations/inventory/adjustments/${adjustment.id}/submit`, {}, { onSuccess: () => router.reload() });
    }

    function actionApprove() {
        if (!confirm('Approve this adjustment? Stock levels will be updated immediately.')) return;
        router.post(`/operations/inventory/adjustments/${adjustment.id}/approve`, {}, { onSuccess: () => router.reload() });
    }

    function actionReject() {
        if (!rejectionReason.trim()) {
            alert('Rejection reason is required.');
            return;
        }
        router.post(`/operations/inventory/adjustments/${adjustment.id}/reject`, { rejection_reason: rejectionReason }, {
            onSuccess: () => {
                setRejecting(false);
                router.reload();
            }
        });
    }

    function actionCancel() {
        if (!confirm('Cancel this adjustment?')) return;
        router.post(`/operations/inventory/adjustments/${adjustment.id}/cancel`, {}, { onSuccess: () => router.reload() });
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/adjustments" className="text-sm text-gray-500 hover:text-gray-700">← Adjustments</Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{adjustment.adjustment_number}</h1>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${typeColors[adjustment.adjustment_type.value] ?? 'bg-gray-100 text-gray-600'}`}>
                        {adjustment.adjustment_type.label}
                    </span>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${statusColors[adjustment.status.value] ?? 'bg-gray-100 text-gray-600'}`}>
                        {adjustment.status.label}
                    </span>
                </div>
                <div className="flex gap-2 flex-wrap">
                    {isDraft && can('inventory.adjustment.edit') && (
                        <Link href={`/operations/inventory/adjustments/${adjustment.id}/edit`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Edit</Link>
                    )}
                    {isDraft && can('inventory.adjustment.submit') && (
                        <button onClick={actionSubmit}
                            className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">Submit</button>
                    )}
                    {isSubmitted && can('inventory.adjustment.approve') && (
                        <button onClick={actionApprove}
                            className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700">Approve</button>
                    )}
                    {isSubmitted && can('inventory.adjustment.reject') && !rejecting && (
                        <button onClick={() => setRejecting(true)}
                            className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm hover:bg-red-100 border border-red-200">Reject</button>
                    )}
                    {(isDraft || isSubmitted) && can('inventory.adjustment.cancel') && !rejecting && (
                        <button onClick={actionCancel}
                            className="bg-gray-100 text-gray-600 px-4 py-2 rounded text-sm hover:bg-gray-200">Cancel</button>
                    )}
                </div>
            </div>

            {rejecting && (
                <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 flex items-start gap-4">
                    <div className="flex-1">
                        <label className="block text-sm font-medium text-red-800 mb-1">Reason for Rejection</label>
                        <input type="text" value={rejectionReason} onChange={(e) => setRejectionReason(e.target.value)}
                            className="border border-red-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-red-500" autoFocus />
                    </div>
                    <div className="pt-6 flex gap-2">
                        <button onClick={actionReject} className="bg-red-600 text-white px-4 py-2 rounded text-sm hover:bg-red-700">Confirm Reject</button>
                        <button onClick={() => setRejecting(false)} className="bg-white text-gray-700 border border-gray-300 px-4 py-2 rounded text-sm hover:bg-gray-50">Cancel</button>
                    </div>
                </div>
            )}

            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Adjustment Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div><p className="text-xs text-gray-500 mb-1">Number</p><p className="text-sm font-mono">{adjustment.adjustment_number}</p></div>
                    <div><p className="text-xs text-gray-500 mb-1">Location</p><p className="text-sm">{adjustment.location?.name ?? adjustment.location_id}</p></div>
                    {adjustment.submitted_at && <div><p className="text-xs text-gray-500 mb-1">Submitted At</p><p className="text-sm">{adjustment.submitted_at}</p></div>}
                    {adjustment.approved_at && <div><p className="text-xs text-gray-500 mb-1">Approved At</p><p className="text-sm">{adjustment.approved_at}</p></div>}
                    {adjustment.rejected_at && <div><p className="text-xs text-gray-500 mb-1">Rejected At</p><p className="text-sm">{adjustment.rejected_at}</p></div>}
                </div>
                {adjustment.reason && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Reason</p>
                        <p className="text-sm text-gray-700">{adjustment.reason}</p>
                    </div>
                )}
                {adjustment.rejection_reason && (
                    <div className="mt-4 pt-4 border-t border-red-100 bg-red-50 p-4 rounded text-red-800">
                        <p className="text-xs font-semibold mb-1">Rejection Reason</p>
                        <p className="text-sm">{adjustment.rejection_reason}</p>
                    </div>
                )}
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Lines</h2>
                </div>
                {!adjustment.lines || adjustment.lines.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">No lines.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">System Qty</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Actual Qty</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Variance</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Cost</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {adjustment.lines.map((l) => (
                                    <tr key={l.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3">
                                            <p className="font-medium text-gray-900">{l.item?.name ?? l.item_id}</p>
                                            <p className="text-xs font-mono text-gray-400">{l.item?.item_code}</p>
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-500">{l.quantity_system}</td>
                                        <td className="px-6 py-3 text-right font-mono font-medium">{l.quantity_actual}</td>
                                        <td className={`px-6 py-3 text-right font-mono font-bold ${l.quantity_variance > 0 ? 'text-green-600' : l.quantity_variance < 0 ? 'text-red-600' : 'text-gray-400'}`}>
                                            {l.quantity_variance > 0 ? '+' : ''}{l.quantity_variance}
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-500">{l.unit_cost != null ? l.unit_cost.toFixed(4) : '—'}</td>
                                        <td className="px-6 py-3 text-gray-500 text-xs">{l.notes ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="text-xs text-gray-400">Created {adjustment.created_at} · Updated {adjustment.updated_at}</div>
        </AppLayout>
    );
}
