import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface StockCard {
    id:              string;
    property_id:     string;
    item_id:         string;
    location_id:     string;
    movement_type:   EnumOption;
    quantity_before: number;
    quantity_change: number;
    quantity_after:  number;
    unit_cost:       number | null;
    total_value:     number | null;
    reference_type:  string | null;
    reference_id:    string | null;
    notes:           string | null;
    posted_by:       string | null;
    posted_at:       string | null;
    item?:    { id: string; item_code: string; name: string } | null;
    location?: { id: string; location_code: string; name: string } | null;
    posted_by_user?: { id: string; name: string } | null;
}

interface Props { stock_card: StockCard; }

const movementColors: Record<string, string> = {
    receipt:      'bg-green-100 text-green-700',
    issue:        'bg-red-100 text-red-700',
    transfer_in:  'bg-blue-100 text-blue-700',
    transfer_out: 'bg-orange-100 text-orange-700',
    adjustment:   'bg-purple-100 text-purple-700',
    opening:      'bg-gray-100 text-gray-600',
};

export default function StockCardShow({ stock_card: sc }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/stock-cards" className="text-sm text-gray-500 hover:text-gray-700">← Stock Cards</Link>
            </div>

            <div className="flex items-center gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Stock Card Entry</h1>
                <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${movementColors[sc.movement_type.value] ?? 'bg-gray-100 text-gray-600'}`}>
                    {sc.movement_type.label}
                </span>
            </div>

            {/* Main details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Movement Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-3">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Item</p>
                        {sc.item ? (
                            <Link href={`/operations/inventory/items/${sc.item_id}`} className="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                {sc.item.name}
                                <span className="block font-mono text-xs text-gray-400">{sc.item.item_code}</span>
                            </Link>
                        ) : <p className="text-sm font-mono text-gray-700">{sc.item_id}</p>}
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Location</p>
                        <p className="text-sm text-gray-700">{sc.location?.name ?? sc.location_id}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Movement Type</p>
                        <p className="text-sm text-gray-700">{sc.movement_type.label}</p>
                    </div>
                </div>
            </div>

            {/* Quantity & Cost */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Quantities & Cost</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-5">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Qty Before</p>
                        <p className="text-xl font-mono font-medium text-gray-700">{sc.quantity_before}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Change</p>
                        <p className={`text-xl font-mono font-bold ${sc.quantity_change >= 0 ? 'text-green-700' : 'text-red-600'}`}>
                            {sc.quantity_change >= 0 ? '+' : ''}{sc.quantity_change}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Qty After</p>
                        <p className="text-xl font-mono font-bold text-gray-900">{sc.quantity_after}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Unit Cost</p>
                        <p className="text-xl font-mono text-gray-700">{sc.unit_cost != null ? sc.unit_cost.toFixed(4) : '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Total Value</p>
                        <p className="text-xl font-mono text-gray-700">{sc.total_value != null ? sc.total_value.toFixed(2) : '—'}</p>
                    </div>
                </div>
            </div>

            {/* Reference & Audit */}
            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Reference & Audit</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-3">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Reference Type</p>
                        <p className="text-sm text-gray-700">{sc.reference_type ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Reference ID</p>
                        <p className="text-sm font-mono text-gray-700">{sc.reference_id ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Posted At</p>
                        <p className="text-sm text-gray-700">{sc.posted_at ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Posted By</p>
                        <p className="text-sm text-gray-700">{sc.posted_by_user?.name ?? sc.posted_by ?? '—'}</p>
                    </div>
                </div>
                {sc.notes && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Notes</p>
                        <p className="text-sm text-gray-700">{sc.notes}</p>
                    </div>
                )}
                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Entry ID: <span className="font-mono">{sc.id}</span>
                    <span className="ml-4 text-gray-300">Stock cards are immutable — no edits or deletes.</span>
                </div>
            </div>
        </AppLayout>
    );
}
