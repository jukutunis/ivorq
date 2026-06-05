import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { InventoryLocation, PageProps } from '@/Types';

interface Props { location: InventoryLocation; }

export default function LocationShow({ location }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);

    function destroy() {
        if (confirm(`Delete location "${location.name}"? This cannot be undone.`)) {
            router.delete(`/operations/inventory/locations/${location.id}`);
        }
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/locations" className="text-sm text-gray-500 hover:text-gray-700">← Locations</Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{location.name}</h1>
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700">
                        {location.location_type.label}
                    </span>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${location.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                        {location.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <div className="flex gap-2">
                    {can('inventory.location.edit') && (
                        <Link href={`/operations/inventory/locations/${location.id}/edit`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Edit</Link>
                    )}
                    {can('inventory.location.delete') && (
                        <button onClick={destroy}
                            className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm hover:bg-red-100 border border-red-200">Delete</button>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Location Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Location Code</p>
                        <p className="text-sm font-mono text-gray-700">{location.location_code}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Name</p>
                        <p className="text-sm font-medium text-gray-900">{location.name}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Type</p>
                        <p className="text-sm text-gray-700">{location.location_type.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Status</p>
                        <p className="text-sm text-gray-700">{location.is_active ? 'Active' : 'Inactive'}</p>
                    </div>
                </div>
                {location.description && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Description</p>
                        <p className="text-sm text-gray-700">{location.description}</p>
                    </div>
                )}
                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {location.created_at} · Updated {location.updated_at}
                </div>
            </div>
        </AppLayout>
    );
}
