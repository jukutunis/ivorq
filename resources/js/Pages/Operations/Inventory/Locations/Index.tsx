import AppLayout from '@/Layouts/AppLayout';
import { EnumOption, InventoryLocation, PageProps, PaginatedData } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { locations: PaginatedData<InventoryLocation>; type_options: EnumOption[]; filters: { name?: string; type?: string }; }

export default function LocationIndex({ locations, type_options, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);
    const label = (value: string) => type_options.find((type) => type.value === value)?.label ?? value.replaceAll('_', ' ');
    const apply = (field: string, value: string) => router.get('/operations/inventory/locations', { ...filters, [field]: value || undefined }, { preserveState: true });
    return <AppLayout>
        <div className="flex items-center justify-between mb-6"><div><h1 className="text-2xl font-bold">Inventory Locations</h1><p className="text-sm text-gray-500">{locations.total} total</p></div>{can('inventory.location.create') && <Link href="/operations/inventory/locations/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm">New Location</Link>}</div>
        <div className="flex gap-3 mb-4"><input value={filters.name ?? ''} onChange={(e) => apply('name', e.target.value)} placeholder="Search by name…" className="border rounded px-3 py-2 text-sm" /><select value={filters.type ?? ''} onChange={(e) => apply('type', e.target.value)} className="border rounded px-3 py-2 text-sm"><option value="">All types</option>{type_options.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select></div>
        <div className="bg-white rounded-lg shadow overflow-hidden"><table className="w-full text-sm"><thead className="bg-gray-50"><tr><th className="text-left px-6 py-3">Name</th><th className="text-left px-6 py-3">Type</th><th className="text-left px-6 py-3">Parent</th><th /></tr></thead><tbody className="divide-y">{locations.data.map((location) => <tr key={location.id}><td className="px-6 py-4 font-medium">{location.name}</td><td className="px-6 py-4 capitalize">{label(location.type)}</td><td className="px-6 py-4 font-mono text-xs text-gray-500">{location.parent_id ?? '—'}</td><td className="px-6 py-4 text-right"><Link href={`/operations/inventory/locations/${location.id}`} className="text-blue-600">View</Link></td></tr>)}</tbody></table>{locations.data.length === 0 && <p className="p-12 text-center text-gray-400 text-sm">No locations found.</p>}</div>
    </AppLayout>;
}
