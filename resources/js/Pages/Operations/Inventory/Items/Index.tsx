import AppLayout from '@/Layouts/AppLayout';
import { InventoryItem, PageProps, PaginatedData } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { items: PaginatedData<InventoryItem>; filters: { name?: string; is_active?: string }; }

export default function ItemIndex({ items, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);
    const apply = (field: string, value: string) => router.get('/operations/inventory/items', { ...filters, [field]: value || undefined }, { preserveState: true });
    return <AppLayout>
        <div className="flex items-center justify-between mb-6"><div><h1 className="text-2xl font-bold">Inventory Items</h1><p className="text-sm text-gray-500">{items.total} total</p></div>{can('inventory.item.create') && <Link href="/operations/inventory/items/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm">New Item</Link>}</div>
        <div className="flex gap-3 mb-4"><input value={filters.name ?? ''} onChange={(e) => apply('name', e.target.value)} placeholder="Search by SKU or name…" className="border rounded px-3 py-2 text-sm" /><select value={filters.is_active ?? ''} onChange={(e) => apply('is_active', e.target.value)} className="border rounded px-3 py-2 text-sm"><option value="">All statuses</option><option value="1">Active</option><option value="0">Inactive</option></select></div>
        <div className="bg-white rounded-lg shadow overflow-hidden"><table className="w-full text-sm"><thead className="bg-gray-50"><tr><th className="text-left px-6 py-3">SKU</th><th className="text-left px-6 py-3">Name</th><th className="text-left px-6 py-3">Category</th><th className="text-left px-6 py-3">Type</th><th className="text-right px-6 py-3">WAC</th><th className="text-right px-6 py-3">Reorder point</th><th /></tr></thead><tbody className="divide-y">{items.data.map((item) => <tr key={item.id}><td className="px-6 py-4 font-mono">{item.sku}</td><td className="px-6 py-4 font-medium">{item.name}</td><td className="px-6 py-4">{item.category?.name ?? '—'}</td><td className="px-6 py-4 capitalize">{item.inventory_type.replaceAll('_', ' ')}</td><td className="px-6 py-4 text-right font-mono">{item.weighted_average_cost.toFixed(2)}</td><td className="px-6 py-4 text-right font-mono">{item.reorder_point.toFixed(4)}</td><td className="px-6 py-4 text-right"><Link href={`/operations/inventory/items/${item.id}`} className="text-blue-600">View</Link></td></tr>)}</tbody></table>{items.data.length === 0 && <p className="p-12 text-center text-gray-400 text-sm">No items found.</p>}</div>
    </AppLayout>;
}
