import AppLayout from '@/Layouts/AppLayout';
import { InventoryCategory, PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { category: InventoryCategory; }

export default function CategoryShow({ category }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);
    const destroy = () => confirm(`Delete category "${category.name}"?`) && router.delete(`/operations/inventory/categories/${category.id}`);

    return <AppLayout>
        <Link href="/operations/inventory/categories" className="text-sm text-gray-500">← Categories</Link>
        <div className="flex items-center justify-between my-6"><h1 className="text-2xl font-bold text-gray-900">{category.name}</h1><div className="flex gap-2">{can('inventory.category.edit') && <Link href={`/operations/inventory/categories/${category.id}/edit`} className="bg-gray-100 px-4 py-2 rounded text-sm">Edit</Link>}{can('inventory.category.delete') && <button onClick={destroy} className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm">Delete</button>}</div></div>
        <div className="bg-white rounded-lg shadow p-6 grid gap-6 md:grid-cols-2"><div><p className="text-xs text-gray-500">Description</p><p className="text-sm text-gray-700 mt-1">{category.description ?? '—'}</p></div><div><p className="text-xs text-gray-500">Parent category ID</p><p className="text-sm font-mono text-gray-700 mt-1">{category.parent_id ?? '—'}</p></div></div>
    </AppLayout>;
}
