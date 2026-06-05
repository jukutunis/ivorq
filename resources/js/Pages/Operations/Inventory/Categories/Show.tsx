import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { InventoryCategory, PageProps } from '@/Types';

interface Props { category: InventoryCategory; }

export default function CategoryShow({ category }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);

    function destroy() {
        if (confirm(`Delete category "${category.name}"? This cannot be undone.`)) {
            router.delete(`/operations/inventory/categories/${category.id}`);
        }
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/categories" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Categories
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{category.name}</h1>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${category.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                        {category.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <div className="flex gap-2">
                    {can('inventory.category.edit') && (
                        <Link href={`/operations/inventory/categories/${category.id}/edit`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                            Edit
                        </Link>
                    )}
                    {can('inventory.category.delete') && (
                        <button onClick={destroy}
                            className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm hover:bg-red-100 border border-red-200">
                            Delete
                        </button>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Category Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-3">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Category Code</p>
                        <p className="text-sm font-mono text-gray-700">{category.category_code}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Name</p>
                        <p className="text-sm font-medium text-gray-900">{category.name}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Status</p>
                        <p className="text-sm text-gray-700">{category.is_active ? 'Active' : 'Inactive'}</p>
                    </div>
                </div>

                {category.description && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Description</p>
                        <p className="text-sm text-gray-700">{category.description}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {category.created_at} · Updated {category.updated_at}
                </div>
            </div>
        </AppLayout>
    );
}
