import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface InventoryItemOption {
    id: string;
    name: string;
    sku: string | null;
}

interface ConsumptionEvidence {
    movement_id: string;
    movement_type: string;
    issue_quantity: string;
    avco_at_issue: string;
    occurred_at: string;
}

interface CostControlProjection {
    property_id: string;
    inventory_item_id: string;
    controlled_ledger_quantity: string;
    costed_controlled_quantity: string;
    derived_avco_unit_cost: string | null;
    derived_controlled_cost_value: string | null;
    base_currency_code: string;
    eligibility_status: string;
    blocking_reason: string | null;
    blocking_movement_id: string | null;
    last_cost_eligible_movement_id: string | null;
    last_cost_eligible_at: string | null;
    consumption_cost_evidence: ConsumptionEvidence[];
    projection_as_of: string;
}

interface Props {
    items: InventoryItemOption[];
    projection: CostControlProjection | null;
    selectedItemId: string | null;
}

function eligibilityBadge(status: string) {
    const map: Record<string, string> = {
        COSTING_READY: 'bg-emerald-100 text-emerald-700',
        COSTING_BLOCKED_FX_UNSUPPORTED: 'bg-amber-100 text-amber-700',
        COSTING_BLOCKED_UNVALUED_MOVEMENT: 'bg-orange-100 text-orange-700',
        COSTING_BLOCKED_INSUFFICIENT_COST_EVIDENCE: 'bg-red-100 text-red-700',
        COSTING_BLOCKED_INCONSISTENT_MOVEMENT_EVIDENCE: 'bg-red-100 text-red-700',
    };
    const cls = map[status] ?? 'bg-gray-100 text-gray-600';
    const label = status.replace(/_/g, ' ').replace('COSTING ', '');
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {label}
        </span>
    );
}

function formatDecimal(value: string | null): string {
    if (value === null || value === undefined) return '\u2014';
    return parseFloat(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    });
}

export default function InventoryCostControlWorkspace({ items, projection, selectedItemId }: Props) {
    const [isLoading, setIsLoading] = useState(false);

    function selectItem(itemId: string) {
        setIsLoading(true);
        router.get(
            '/operations/inventory/cost-control',
            { inventory_item_id: itemId },
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => setIsLoading(false),
            }
        );
    }

    return (
        <AppLayout>
            <Head title="Controlled Cost Control Evidence" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Controlled AVCO Cost Evidence</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Read-only projection derived from controlled immutable inventory movements
                        and source-proven commercial receipt evidence.
                    </p>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow mb-8 overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Select Inventory Item</h2>
                    <p className="text-xs text-gray-400 mt-0.5">
                        Cost eligibility is projected per item across the active property.
                    </p>
                </div>
                <div className="px-6 py-4">
                    <select
                        value={selectedItemId ?? ''}
                        onChange={(e) => selectItem(e.target.value)}
                        className="w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        disabled={isLoading}
                    >
                        <option value="">-- Select an item --</option>
                        {items.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name} {item.sku ? `(${item.sku})` : ''}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {isLoading && (
                <div className="text-center py-12 text-gray-400 text-sm">
                    Projecting controlled AVCO evidence...
                </div>
            )}

            {!isLoading && !projection && selectedItemId && (
                <div className="text-center py-12 text-gray-400 text-sm">
                    No projection available for the selected item.
                </div>
            )}

            {!isLoading && !selectedItemId && (
                <div className="text-center py-12 text-gray-400 text-sm">
                    Select an inventory item above to view its Controlled AVCO Cost Evidence projection.
                </div>
            )}

            {!isLoading && projection && (
                <>
                    {/* Summary Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <div className="bg-white rounded-lg shadow p-6">
                            <p className="text-xs text-gray-500 uppercase tracking-wider mb-1">
                                Controlled Ledger Quantity
                            </p>
                            <p className="text-2xl font-mono font-bold text-gray-900">
                                {formatDecimal(projection.controlled_ledger_quantity)}
                            </p>
                        </div>

                        <div className="bg-white rounded-lg shadow p-6">
                            <p className="text-xs text-gray-500 uppercase tracking-wider mb-1">
                                Costed Controlled Quantity
                            </p>
                            <p className="text-2xl font-mono font-bold text-gray-900">
                                {formatDecimal(projection.costed_controlled_quantity)}
                            </p>
                        </div>

                        <div className="bg-white rounded-lg shadow p-6">
                            <p className="text-xs text-gray-500 uppercase tracking-wider mb-1">
                                Derived AVCO Unit Cost
                            </p>
                            <p className="text-2xl font-mono font-bold text-gray-900">
                                {projection.derived_avco_unit_cost !== null
                                    ? `${formatDecimal(projection.derived_avco_unit_cost)} ${projection.base_currency_code}`
                                    : '\u2014'}
                            </p>
                        </div>

                        <div className="bg-white rounded-lg shadow p-6">
                            <p className="text-xs text-gray-500 uppercase tracking-wider mb-1">
                                Derived Controlled Cost Value
                            </p>
                            <p className="text-2xl font-mono font-bold text-gray-900">
                                {projection.derived_controlled_cost_value !== null
                                    ? `${formatDecimal(projection.derived_controlled_cost_value)} ${projection.base_currency_code}`
                                    : '\u2014'}
                            </p>
                        </div>
                    </div>

                    {/* Eligibility Status */}
                    <div className="bg-white rounded-lg shadow mb-8 overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-100">
                            <h2 className="text-sm font-semibold text-gray-700">Cost Eligibility Status</h2>
                            <p className="text-xs text-gray-400 mt-0.5">
                                Server-derived status reflecting current controlled movement evidence.
                            </p>
                        </div>
                        <div className="px-6 py-4">
                            <div className="flex items-center gap-3 mb-2">
                                <span className="text-sm font-medium text-gray-700">Status:</span>
                                {eligibilityBadge(projection.eligibility_status)}
                            </div>
                            {projection.blocking_reason && (
                                <div className="mt-2 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                                    <span className="font-medium">Blocking Reason:</span> {projection.blocking_reason}
                                </div>
                            )}
                            {projection.blocking_movement_id && (
                                <p className="mt-2 text-xs text-gray-500">
                                    Blocking Movement: {projection.blocking_movement_id}
                                </p>
                            )}
                            {projection.last_cost_eligible_movement_id && (
                                <p className="mt-1 text-xs text-gray-500">
                                    Last Cost-Eligible Movement: {projection.last_cost_eligible_movement_id}
                                    {projection.last_cost_eligible_at && (
                                        <> ({new Date(projection.last_cost_eligible_at).toLocaleString()})</>
                                    )}
                                </p>
                            )}
                            <p className="mt-2 text-xs text-gray-400">
                                Projection as of: {new Date(projection.projection_as_of).toLocaleString()}
                            </p>
                        </div>
                    </div>

                    {/* Consumption Cost Evidence */}
                    {projection.consumption_cost_evidence.length > 0 && (
                        <div className="bg-white rounded-lg shadow overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-100">
                                <h2 className="text-sm font-semibold text-gray-700">
                                    Issue / Consumption Cost Evidence
                                </h2>
                                <p className="text-xs text-gray-400 mt-0.5">
                                    Read-only derived consumption cost at time of each issue.
                                </p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-gray-50 border-b border-gray-200">
                                        <tr>
                                            <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Movement</th>
                                            <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Issue Qty</th>
                                            <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">AVCO at Issue</th>
                                            <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Occurred At</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {projection.consumption_cost_evidence.map((entry) => (
                                            <tr key={entry.movement_id} className="hover:bg-gray-50">
                                                <td className="px-6 py-3 font-mono text-xs text-gray-600">
                                                    {entry.movement_id}
                                                </td>
                                                <td className="px-6 py-3 text-right font-mono text-gray-700">
                                                    {formatDecimal(entry.issue_quantity)}
                                                </td>
                                                <td className="px-6 py-3 text-right font-mono text-gray-700">
                                                    {formatDecimal(entry.avco_at_issue)} {projection.base_currency_code}
                                                </td>
                                                <td className="px-6 py-3 text-gray-500 text-xs">
                                                    {new Date(entry.occurred_at).toLocaleString()}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </>
            )}

            <div className="mt-8 text-xs text-gray-400 border-t pt-4">
                Controlled AVCO Cost Evidence &mdash; read-only projection. Not financial inventory valuation.
                No cost posting, GL integration, or AP posting is available from this workspace.
            </div>
        </AppLayout>
    );
}
