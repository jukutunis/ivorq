import AppLayout from '@/Layouts/AppLayout';
import { InventoryCategory, PageProps, PaginatedData } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { categories: PaginatedData<InventoryCategory>; filters: { name?: string }; }

export default function CategoryIndex({ categories, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);

    return <AppLayout>
        <div className="flex items-center justify-between mb-6"><div><h1 className="text-2xl font-bold text-gray-900">Inventory Categories</h1><p className="text-sm text-gray-500 mt-1">{categories.total} total</p></div>{can('inventory.category.create') && <Link href="/operations/inventory/categories/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm">New Category</Link>}</div>
        <input value={filters.name ?? ''} onChange={(e) => router.get('/operations/inventory/categories', { name: e.target.value || undefined }, { preserveState: true })} placeholder="Search by name or description…" className="border border-gray-300 rounded px-3 py-2 text-sm w-64 mb-4" />
        <div className="bg-white rounded-lg shadow overflow-hidden"><table className="w-full text-sm"><thead className="bg-gray-50"><tr><th className="text-left px-6 py-3">Name</th><th className="text-left px-6 py-3">Description</th><th className="text-left px-6 py-3">Parent</th><th /></tr></thead><tbody className="divide-y">{categories.data.map((category) => <tr key={category.id}><td className="px-6 py-4 font-medium">{category.name}</td><td className="px-6 py-4 text-gray-500">{category.description ?? '—'}</td><td className="px-6 py-4 font-mono text-xs text-gray-500">{category.parent_id ?? '—'}</td><td className="px-6 py-4 text-right"><Link href={`/operations/inventory/categories/${category.id}`} className="text-blue-600">View</Link></td></tr>)}</tbody></table>{categories.data.length === 0 && <p className="p-12 text-center text-gray-400 text-sm">No categories found.</p>}</div>
    </AppLayout>;
}
