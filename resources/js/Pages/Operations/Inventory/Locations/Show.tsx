import AppLayout from '@/Layouts/AppLayout';
import { InventoryLocation, PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { location: InventoryLocation; }

export default function LocationShow({ location }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);
    const destroy = () => confirm(`Delete location "${location.name}"?`) && router.delete(`/operations/inventory/locations/${location.id}`);
    return <AppLayout>
        <Link href="/operations/inventory/locations" className="text-sm text-gray-500">← Locations</Link><div className="flex items-center justify-between my-6"><h1 className="text-2xl font-bold">{location.name}</h1><div className="flex gap-2">{can('inventory.location.edit') && <Link href={`/operations/inventory/locations/${location.id}/edit`} className="bg-gray-100 px-4 py-2 rounded text-sm">Edit</Link>}{can('inventory.location.delete') && <button onClick={destroy} className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm">Delete</button>}</div></div>
        <div className="bg-white rounded-lg shadow p-6 grid gap-6 md:grid-cols-2"><div><p className="text-xs text-gray-500">Type</p><p className="text-sm capitalize mt-1">{location.type.replaceAll('_', ' ')}</p></div><div><p className="text-xs text-gray-500">Parent location ID</p><p className="text-sm font-mono mt-1">{location.parent_id ?? '—'}</p></div></div>
    </AppLayout>;
}
