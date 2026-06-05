import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { EnumOption, PageProps } from '@/Types';

interface TransferLine {
    id: string; item_id: string; quantity_requested: number; notes: string | null;
    item?: { id: string; item_code: string; name: string } | null;
}
interface Transfer {
    id: string; transfer_number: string;
    from_location_id: string; to_location_id: string;
    status: EnumOption; notes: string | null;
    requested_by: string | null; completed_at: string | null; cancelled_at: string | null;
    created_at: string; updated_at: string;
    from_location?: { id: string; name: string } | null;
    to_location?:   { id: string; name: string } | null;
    lines?: TransferLine[];
}

interface Props { transfer: Transfer; }

const statusColors: Record<string, string> = {
    pending:   'bg-amber-100 text-amber-700',
    completed: 'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-600',
};

export default function TransferShow({ transfer }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);
    const isPending = transfer.status.value === 'pending';

    function actionComplete() {
        if (!confirm('Complete this transfer? Stock will move from source to destination.')) return;
        router.post(`/operations/inventory/transfers/${transfer.id}/complete`, {}, { onSuccess: () => router.reload() });
    }

    function actionCancel() {
        if (!confirm('Cancel this transfer? This cannot be undone.')) return;
        router.post(`/operations/inventory/transfers/${transfer.id}/cancel`, {}, { onSuccess: () => router.reload() });
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/transfers" className="text-sm text-gray-500 hover:text-gray-700">← Transfers</Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{transfer.transfer_number}</h1>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${statusColors[transfer.status.value] ?? 'bg-gray-100 text-gray-600'}`}>
                        {transfer.status.label}
                    </span>
                </div>
                <div className="flex gap-2 flex-wrap">
                    {isPending && can('inventory.transfer.edit') && (
                        <Link href={`/operations/inventory/transfers/${transfer.id}/edit`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Edit</Link>
                    )}
                    {isPending && can('inventory.transfer.complete') && (
                        <button onClick={actionComplete}
                            className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700">Complete</button>
                    )}
                    {isPending && can('inventory.transfer.cancel') && (
                        <button onClick={actionCancel}
                            className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm hover:bg-red-100 border border-red-200">Cancel</button>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Transfer Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div><p className="text-xs text-gray-500 mb-1">Number</p><p className="text-sm font-mono">{transfer.transfer_number}</p></div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">From</p>
                        <p className="text-sm">{transfer.from_location?.name ?? transfer.from_location_id}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">To</p>
                        <p className="text-sm">{transfer.to_location?.name ?? transfer.to_location_id}</p>
                    </div>
                    {transfer.completed_at && <div><p className="text-xs text-gray-500 mb-1">Completed At</p><p className="text-sm">{transfer.completed_at}</p></div>}
                    {transfer.cancelled_at && <div><p className="text-xs text-gray-500 mb-1">Cancelled At</p><p className="text-sm">{transfer.cancelled_at}</p></div>}
                </div>
                {transfer.notes && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Notes</p>
                        <p className="text-sm text-gray-700">{transfer.notes}</p>
                    </div>
                )}
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Lines</h2>
                </div>
                {!transfer.lines || transfer.lines.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">No lines.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Qty Requested</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {transfer.lines.map((l) => (
                                    <tr key={l.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3">
                                            <p className="font-medium text-gray-900">{l.item?.name ?? l.item_id}</p>
                                            <p className="text-xs font-mono text-gray-400">{l.item?.item_code}</p>
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono">{l.quantity_requested}</td>
                                        <td className="px-6 py-3 text-gray-500 text-xs">{l.notes ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="text-xs text-gray-400">Created {transfer.created_at} · Updated {transfer.updated_at}</div>
        </AppLayout>
    );
}
