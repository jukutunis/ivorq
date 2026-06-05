import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { PaginatedData, EnumOption, PageProps } from '@/Types';

interface Adjustment {
    id: string; adjustment_number: string; location_id: string;
    adjustment_type: EnumOption; status: EnumOption; reason: string | null;
    submitted_at: string | null; approved_at: string | null; rejected_at: string | null;
    created_at: string; lines_count?: number;
    location?: { id: string; name: string } | null;
}

interface Props {
    adjustments: PaginatedData<Adjustment>;
    statuses: EnumOption[];
    adjustment_types: EnumOption[];
    filters: { status?: string; adjustment_type?: string; location_id?: string };
}

const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-600',
    submitted: 'bg-blue-100 text-blue-700',
    approved:  'bg-green-100 text-green-700',
    rejected:  'bg-red-100 text-red-600',
    cancelled: 'bg-gray-200 text-gray-800',
};

const typeColors: Record<string, string> = {
    physical_count: 'bg-indigo-50 text-indigo-700 border border-indigo-200',
    damage:         'bg-orange-50 text-orange-700 border border-orange-200',
    loss:           'bg-red-50 text-red-700 border border-red-200',
    other:          'bg-gray-50 text-gray-700 border border-gray-200',
};

export default function AdjustmentIndex({ adjustments, statuses, adjustment_types, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);

    function applyFilter(field: string, value: string) {
        router.get('/operations/inventory/adjustments', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Inventory Adjustments</h1>
                    <p className="text-sm text-gray-500 mt-1">{adjustments.total} adjustment{adjustments.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/inventory" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Dashboard</Link>
                    {can('inventory.adjustment.create') && (
                        <Link href="/operations/inventory/adjustments/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">New Adjustment</Link>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap gap-3 mb-4">
                <select value={filters.status ?? ''}
                    onChange={(e) => applyFilter('status', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Statuses</option>
                    {statuses.map((s) => <option key={String(s.value)} value={String(s.value)}>{s.label}</option>)}
                </select>
                <select value={filters.adjustment_type ?? ''}
                    onChange={(e) => applyFilter('adjustment_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Types</option>
                    {adjustment_types.map((t) => <option key={String(t.value)} value={String(t.value)}>{t.label}</option>)}
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {adjustments.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">No adjustments found.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Lines</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted At</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {adjustments.data.map((adj) => (
                                    <tr key={adj.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 font-mono text-xs text-gray-700">{adj.adjustment_number}</td>
                                        <td className="px-6 py-4 text-gray-700">{adj.location?.name ?? adj.location_id}</td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${typeColors[adj.adjustment_type.value] ?? 'bg-gray-100 text-gray-600'}`}>
                                                {adj.adjustment_type.label}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${statusColors[adj.status.value] ?? 'bg-gray-100 text-gray-600'}`}>
                                                {adj.status.label}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right text-gray-600">{adj.lines_count ?? '—'}</td>
                                        <td className="px-6 py-4 text-gray-500 text-xs">{adj.submitted_at ?? '—'}</td>
                                        <td className="px-6 py-4 text-right">
                                            <Link href={`/operations/inventory/adjustments/${adj.id}`} className="text-blue-600 hover:text-blue-800 text-sm">View</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {adjustments.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {adjustments.current_page} of {adjustments.last_page}</span>
                        <div className="flex gap-1">
                            {adjustments.links.map((link, i) => (
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
