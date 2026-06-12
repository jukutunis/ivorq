import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { InventoryStockCard } from '@/Types';

interface Props {
    recent_movements:    InventoryStockCard[];
    low_stock_count:     number;
    out_of_stock_count:  number;
}

function movementBadge(type: { value: string | number; label: string }) {
    const map: Record<string, string> = {
        receipt:    'bg-green-100 text-green-700',
        issue:      'bg-red-100 text-red-700',
        transfer_in:  'bg-blue-100 text-blue-700',
        transfer_out: 'bg-orange-100 text-orange-700',
        adjustment: 'bg-purple-100 text-purple-700',
        opening:    'bg-gray-100 text-gray-600',
    };
    const cls = map[String(type.value)] ?? 'bg-gray-100 text-gray-600';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {type.label}
        </span>
    );
}

function StatCard({ label, value, href, color }: { label: string; value: number; href: string; color: string }) {
    return (
        <Link href={href} className="bg-white rounded-lg shadow p-6 hover:shadow-md transition-shadow">
            <p className="text-sm text-gray-500 mb-1">{label}</p>
            <p className={`text-3xl font-bold ${color}`}>{value}</p>
        </Link>
    );
}

export default function InventoryDashboard({ recent_movements, low_stock_count, out_of_stock_count }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Inventory</h1>
                    <p className="text-sm text-gray-500 mt-1">Stock overview and recent movements</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/inventory/items" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Items
                    </Link>
                    <Link href="/operations/inventory/stock-cards" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Stock Cards
                    </Link>
                </div>
            </div>

            {/* KPI Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
                <StatCard label="Low Stock Items"   value={low_stock_count}    href="/operations/inventory/items" color={low_stock_count > 0 ? 'text-amber-600' : 'text-gray-900'} />
                <StatCard label="Out of Stock"      value={out_of_stock_count} href="/operations/inventory/items" color={out_of_stock_count > 0 ? 'text-red-600' : 'text-gray-900'} />
                <StatCard label="Pending Receipts"  value={0}  href="/operations/inventory/receipts"    color="text-blue-600" />
                <StatCard label="Pending Transfers" value={0}  href="/operations/inventory/transfers"   color="text-violet-600" />
            </div>

            {/* Quick links */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-8">
                {[
                    { label: 'Categories',  href: '/operations/inventory/categories' },
                    { label: 'Units',       href: '/operations/inventory/units' },
                    { label: 'Locations',   href: '/operations/inventory/locations' },
                    { label: 'Items',       href: '/operations/inventory/items' },
                    { label: 'Stock Cards', href: '/operations/inventory/stock-cards' },
                ].map((l) => (
                    <Link key={l.href} href={l.href}
                        className="bg-white border border-gray-200 rounded-lg px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-300 transition-colors text-center">
                        {l.label}
                    </Link>
                ))}
            </div>

            {/* Recent movements */}
            <div className="bg-white rounded-lg shadow overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-700">Recent Movements</h2>
                    <Link href="/operations/inventory/stock-cards" className="text-xs text-blue-600 hover:text-blue-800">
                        View all →
                    </Link>
                </div>

                {recent_movements.length === 0 ? (
                    <div className="px-6 py-10 text-center text-gray-400 text-sm">No movements recorded yet.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Change</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Posted At</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {recent_movements.map((mv) => (
                                    <tr key={mv.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3 font-medium text-gray-900">
                                            {mv.item ? (
                                                <Link href={`/operations/inventory/items/${mv.item_id}`} className="hover:text-blue-600">
                                                    {mv.item.name}
                                                </Link>
                                            ) : mv.item_id}
                                        </td>
                                        <td className="px-6 py-3 text-gray-600">{mv.location?.name ?? mv.location_id}</td>
                                        <td className="px-6 py-3">{movementBadge(mv.movement_type)}</td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-700">
                                            {(mv as any).quantity_change > 0 ? '+' : ''}{(mv as any).quantity_change}
                                        </td>
                                        <td className="px-6 py-3 text-right font-mono text-gray-700">{(mv as any).quantity_after}</td>
                                        <td className="px-6 py-3 text-gray-500 text-xs">{(mv as any).posted_at ?? '—'}</td>
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
