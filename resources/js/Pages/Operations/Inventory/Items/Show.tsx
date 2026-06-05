import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { InventoryItem, PageProps } from '@/Types';

interface Props { item: InventoryItem; }

export default function ItemShow({ item }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);

    function destroy() {
        if (confirm(`Delete item "${item.name}"? This cannot be undone.`)) {
            router.delete(`/operations/inventory/items/${item.id}`);
        }
    }

    const res = item as any;

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/items" className="text-sm text-gray-500 hover:text-gray-700">← Items</Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{item.name}</h1>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${item.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                        {item.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <div className="flex gap-2">
                    {can('inventory.item.view') && (
                        <Link href={`/operations/inventory/stock-cards?item_id=${item.id}`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                            Stock Cards
                        </Link>
                    )}
                    {can('inventory.item.edit') && (
                        <Link href={`/operations/inventory/items/${item.id}/edit`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Edit</Link>
                    )}
                    {can('inventory.item.delete') && (
                        <button onClick={destroy}
                            className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm hover:bg-red-100 border border-red-200">Delete</button>
                    )}
                </div>
            </div>

            {/* Item Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Item Information</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Item Code</p>
                        <p className="text-sm font-mono text-gray-700">{item.item_code}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Category</p>
                        <p className="text-sm text-gray-700">
                            {item.category ? (
                                <Link href={`/operations/inventory/categories/${item.category_id}`} className="text-blue-600 hover:text-blue-800">
                                    {item.category.name}
                                </Link>
                            ) : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Unit of Measure</p>
                        <p className="text-sm text-gray-700">{item.unit ? `${item.unit.name} (${item.unit.abbreviation})` : '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Average Cost (WAC)</p>
                        <p className="text-sm font-mono font-medium text-gray-900">{item.average_cost.toFixed(4)}</p>
                    </div>
                    {res.sku && (
                        <div>
                            <p className="text-xs text-gray-500 mb-1">SKU</p>
                            <p className="text-sm font-mono text-gray-700">{res.sku}</p>
                        </div>
                    )}
                    {res.barcode && (
                        <div>
                            <p className="text-xs text-gray-500 mb-1">Barcode</p>
                            <p className="text-sm font-mono text-gray-700">{res.barcode}</p>
                        </div>
                    )}
                </div>

                {item.description && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Description</p>
                        <p className="text-sm text-gray-700">{item.description}</p>
                    </div>
                )}
            </div>

            {/* Stock Levels */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Stock Levels</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Min Stock</p>
                        <p className="text-sm font-mono text-gray-700">{res.min_stock != null ? res.min_stock : '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Max Stock</p>
                        <p className="text-sm font-mono text-gray-700">{res.max_stock != null ? res.max_stock : '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Reorder Point</p>
                        <p className="text-sm font-mono text-gray-700">{item.reorder_point != null ? item.reorder_point : '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Reorder Quantity</p>
                        <p className="text-sm font-mono text-gray-700">{res.reorder_quantity != null ? res.reorder_quantity : '—'}</p>
                    </div>
                </div>
            </div>

            {/* Stock Balances */}
            {item.stock_balances && item.stock_balances.length > 0 && (
                <div className="bg-white rounded-lg shadow overflow-hidden mb-6">
                    <div className="px-6 py-4 border-b border-gray-100">
                        <h2 className="text-sm font-semibold text-gray-700">Stock Balances by Location</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Qty on Hand</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {item.stock_balances.map((bal) => (
                                    <tr key={bal.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-3 text-gray-700">{bal.location?.name ?? bal.location_id}</td>
                                        <td className="px-6 py-3 text-right font-mono font-medium text-gray-900">{bal.quantity}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="text-xs text-gray-400">
                Created {item.created_at} · Updated {item.updated_at}
            </div>
        </AppLayout>
    );
}
