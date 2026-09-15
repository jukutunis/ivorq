import AppLayout from '@/Layouts/AppLayout';
import { InventoryUnit, PageProps, PaginatedData } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { units: PaginatedData<InventoryUnit>; filters: { name?: string }; }

export default function UnitIndex({ units, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);
    return <AppLayout>
        <div className="flex items-center justify-between mb-6"><div><h1 className="text-2xl font-bold">Inventory Units</h1><p className="text-sm text-gray-500">{units.total} total</p></div>{can('inventory.unit.create') && <Link href="/operations/inventory/units/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm">New Unit</Link>}</div>
        <input value={filters.name ?? ''} onChange={(e) => router.get('/operations/inventory/units', { name: e.target.value || undefined }, { preserveState: true })} placeholder="Search by code or name…" className="border rounded px-3 py-2 text-sm w-64 mb-4" />
        <div className="bg-white rounded-lg shadow overflow-hidden"><table className="w-full text-sm"><thead className="bg-gray-50"><tr><th className="text-left px-6 py-3">Code</th><th className="text-left px-6 py-3">Name</th><th /></tr></thead><tbody className="divide-y">{units.data.map((unit) => <tr key={unit.id}><td className="px-6 py-4 font-mono">{unit.code}</td><td className="px-6 py-4 font-medium">{unit.name}</td><td className="px-6 py-4 text-right"><Link href={`/operations/inventory/units/${unit.id}`} className="text-blue-600">View</Link></td></tr>)}</tbody></table>{units.data.length === 0 && <p className="p-12 text-center text-gray-400 text-sm">No units found.</p>}</div>
    </AppLayout>;
}
