import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { InventoryLocation, PaginatedData, EnumOption, PageProps } from '@/Types';

interface Props {
    locations:      PaginatedData<InventoryLocation>;
    location_types: EnumOption[];
    filters:        { name?: string; location_type?: string; is_active?: string };
}

export default function LocationIndex({ locations, location_types, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);

    function applyFilter(field: string, value: string) {
        router.get('/operations/inventory/locations', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Inventory Locations</h1>
                    <p className="text-sm text-gray-500 mt-1">{locations.total} location{locations.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/inventory" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Dashboard</Link>
                    {can('inventory.location.create') && (
                        <Link href="/operations/inventory/locations/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">New Location</Link>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap gap-3 mb-4">
                <input type="text" value={filters.name ?? ''}
                    onChange={(e) => applyFilter('name', e.target.value)}
                    placeholder="Search by name…"
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-56" />
                <select value={filters.location_type ?? ''}
                    onChange={(e) => applyFilter('location_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Types</option>
                    {location_types.map((t) => (
                        <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                    ))}
                </select>
                <select value={filters.is_active ?? ''}
                    onChange={(e) => applyFilter('is_active', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {locations.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">No locations found.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {locations.data.map((loc) => (
                                    <tr key={loc.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 font-mono text-xs text-gray-600">{loc.location_code}</td>
                                        <td className="px-6 py-4 font-medium text-gray-900">{loc.name}</td>
                                        <td className="px-6 py-4">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700">
                                                {loc.location_type.label}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${loc.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                                                {loc.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <Link href={`/operations/inventory/locations/${loc.id}`} className="text-blue-600 hover:text-blue-800 text-sm">View</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {locations.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {locations.current_page} of {locations.last_page}</span>
                        <div className="flex gap-1">
                            {locations.links.map((link, i) => (
                                link.url ? (
                                    <Link key={i} href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${link.active ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span key={i} className="px-3 py-1 rounded border border-gray-200 text-xs text-gray-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
