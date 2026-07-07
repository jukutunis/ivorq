import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';

interface MovementTypeOption {
    value: string;
    label: string;
}

interface InventoryItem {
    id: string;
    name: string;
    sku: string | null;
}

interface InventoryLocation {
    id: string;
    name: string;
}

interface InventoryUnit {
    id: string;
    name: string;
    abbreviation: string | null;
}

interface User {
    id: string;
    name: string;
}

interface StockMovement {
    id: string;
    property_id: string;
    inventory_item_id: string;
    inventory_location_id: string;
    inventory_unit_id: string;
    movement_type: MovementTypeOption;
    direction: MovementTypeOption;
    quantity: number;
    source_domain: string;
    source_type: string;
    source_id: string;
    correlation_id: string;
    idempotency_key: string;
    occurred_at: string;
    created_by: string;
    created_at: string;
    item: InventoryItem | null;
    location: InventoryLocation | null;
    unit: InventoryUnit | null;
    created_by_user: User | null;
}

interface StockOnHandRow {
    inventory_item_id: string;
    inventory_location_id: string;
    inventory_unit_id: string;
    controlled_quantity: number;
    item: InventoryItem | null;
    location: InventoryLocation | null;
    unit: InventoryUnit | null;
}

interface PaginatedMovements {
    data: StockMovement[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    movements: PaginatedMovements;
    stockOnHand: StockOnHandRow[];
    movementTypes: MovementTypeOption[];
}

function movementBadge(type: string) {
    const map: Record<string, string> = {
        GOODS_RECEIPT: 'bg-green-100 text-green-700',
    };
    const cls = map[type] ?? 'bg-gray-100 text-gray-600';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {type === 'GOODS_RECEIPT' ? 'Goods Receipt' : type}
        </span>
    );
}

function directionBadge(direction: string) {
    const map: Record<string, string> = {
        IN: 'bg-blue-100 text-blue-700',
        OUT: 'bg-orange-100 text-orange-700',
    };
    const cls = map[direction] ?? 'bg-gray-100 text-gray-600';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {direction}
        </span>
    );
}

export default function InventoryLedgerWorkspace({ movements, stockOnHand }: Props) {
    const isEmpty = movements.data.length === 0;

    return (
        <AppLayout>
            <Head title="Inventory Ledger" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Controlled Inventory Ledger</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Inventory Ledger Foundation active. Costing and valuation deferred.
                    </p>
                </div>
            </div>

            {/* Controlled Ledger Quantity */}
            <div className="bg-white rounded-lg shadow mb-8 overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Controlled Ledger Quantity</h2>
                    <p className="text-xs text-gray-400 mt-0.5">
                        Derived from successful immutable controlled movements. Not complete enterprise stock-on-hand.
                    </p>
                </div>

                {stockOnHand.length === 0 ? (
                    <div className="px-6 py-10 text-center text-gray-400 text-sm">
                        No controlled inventory quantities recorded yet.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">UOM</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Controlled Quantity</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {stockOnHand.map((row, i) => (
                                    <tr key={`${row.inventory_item_id}-${row.inventory_location_id}-${i}`} className="hover:bg-gray-50">
                                        <td className="px-6 py-3 font-medium text-gray-900">
                                            {row.item?.name ?? row.inventory_item_id}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">
                                            {row.location?.name ?? row.inventory_location_id}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">
                                            {row.unit?.abbreviation ?? row.unit?.name ?? row.inventory_unit_id}
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-700">
                                            {row.controlled_quantity}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Recent Controlled Ledger Movements */}
            <div className="bg-white rounded-lg shadow overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Recent Movements</h2>
                </div>

                {isEmpty ? (
                    <div className="px-6 py-10 text-center text-gray-400 text-sm">
                        No controlled ledger movements recorded. Controlled empty state.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Direction</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Occurred At</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {movements.data.map((mv) => (
                                    <tr key={mv.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3 font-medium text-gray-900">
                                            {mv.item?.name ?? mv.inventory_item_id}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">
                                            {mv.location?.name ?? mv.inventory_location_id}
                                        </td>
                                        <td className="px-6 py-3">
                                            {movementBadge(typeof mv.movement_type === 'string' ? mv.movement_type : mv.movement_type.value)}
                                        </td>
                                        <td className="px-6 py-3">
                                            {directionBadge(typeof mv.direction === 'string' ? mv.direction : mv.direction.value)}
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-700">
                                            {mv.quantity}
                                        </td>
                                        <td className="px-6 py-3 text-gray-500 text-xs">
                                            {mv.occurred_at ? new Date(mv.occurred_at).toLocaleDateString() : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {movements.last_page > 1 && (
                    <div className="px-6 py-3 border-t border-gray-100 flex items-center justify-between">
                        <span className="text-xs text-gray-500">
                            Showing {movements.from ?? 0}–{movements.to ?? 0} of {movements.total}
                        </span>
                        <div className="flex gap-1">
                            {movements.prev_page_url && (
                                <a href={movements.prev_page_url} className="px-3 py-1 text-xs bg-gray-100 rounded hover:bg-gray-200">
                                    Previous
                                </a>
                            )}
                            {movements.next_page_url && (
                                <a href={movements.next_page_url} className="px-3 py-1 text-xs bg-gray-100 rounded hover:bg-gray-200">
                                    Next
                                </a>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
