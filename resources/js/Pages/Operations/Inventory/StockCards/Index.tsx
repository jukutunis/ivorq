import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { PaginatedData, EnumOption } from '@/Types';

// Resource shape (from InventoryStockCardResource)
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
}

interface Props {
    stock_cards:    PaginatedData<StockCard>;
    movement_types: EnumOption[];
    filters:        { item_id?: string; location_id?: string; movement_type?: string };
}

const movementColors: Record<string, string> = {
    receipt:      'bg-green-100 text-green-700',
    issue:        'bg-red-100 text-red-700',
    transfer_in:  'bg-blue-100 text-blue-700',
    transfer_out: 'bg-orange-100 text-orange-700',
    adjustment:   'bg-purple-100 text-purple-700',
    opening:      'bg-gray-100 text-gray-600',
};

export default function StockCardIndex({ stock_cards, movement_types, filters }: Props) {
    function applyFilter(field: string, value: string) {
        router.get('/operations/inventory/stock-cards', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Stock Cards</h1>
                    <p className="text-sm text-gray-500 mt-1">{stock_cards.total} movement{stock_cards.total !== 1 ? 's' : ''} — read only ledger</p>
                </div>
                <Link href="/operations/inventory" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                    Dashboard
                </Link>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap gap-3 mb-4">
                <select value={filters.movement_type ?? ''}
                    onChange={(e) => applyFilter('movement_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Movement Types</option>
                    {movement_types.map((t) => (
                        <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {stock_cards.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">No stock card entries found.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Before</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Change</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">After</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Cost</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Total Value</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Posted At</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {stock_cards.data.map((card) => (
                                    <tr key={card.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3 font-medium text-gray-900 max-w-xs">
                                            {card.item ? (
                                                <Link href={`/operations/inventory/items/${card.item_id}`} className="hover:text-blue-600">
                                                    <span className="block text-xs font-mono text-gray-400">{card.item.item_code}</span>
                                                    {card.item.name}
                                                </Link>
                                            ) : card.item_id}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600 text-xs">{card.location?.name ?? card.location_id}</td>
                                        <td className="px-6 py-3">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${movementColors[card.movement_type.value] ?? 'bg-gray-100 text-gray-600'}`}>
                                                {card.movement_type.label}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-500">{card.quantity_before}</td>
                                        <td className={`px-6 py-3 text-right font-mono font-medium ${card.quantity_change >= 0 ? 'text-green-700' : 'text-red-600'}`}>
                                            {card.quantity_change >= 0 ? '+' : ''}{card.quantity_change}
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-900 font-medium">{card.quantity_after}</td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-600">{card.unit_cost != null ? card.unit_cost.toFixed(4) : '—'}</td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-600">{card.total_value != null ? card.total_value.toFixed(2) : '—'}</td>
                                        <td className="px-6 py-3 text-gray-500 text-xs whitespace-nowrap">{card.posted_at ?? '—'}</td>
                                        <td className="px-6 py-3 text-right">
                                            <Link href={`/operations/inventory/stock-cards/${card.id}`} className="text-blue-600 hover:text-blue-800 text-sm">
                                                View
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {stock_cards.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {stock_cards.current_page} of {stock_cards.last_page}</span>
                        <div className="flex gap-1">
                            {stock_cards.links.map((link, i) => (
                                link.url ? (
                                    <Link key={i} href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${link.active ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span key={i} className="px-3 py-1 rounded border border-gray-200 text-xs text-gray-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
