import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { PaginatedData, EnumOption, PageProps } from '@/Types';

interface Issue {
    id: string; issue_number: string; department_id: string | null;
    status: EnumOption; issued_at: string | null; remarks: string | null;
    posted_at: string | null; cancelled_at: string | null;
    created_at: string; lines_count?: number;
    department?: { id: string; name: string } | null;
}

interface Props {
    issues:   PaginatedData<Issue>;
    statuses: EnumOption[];
    filters:  { status?: string; department_id?: string };
}

const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-600',
    posted:    'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-600',
};

export default function IssueIndex({ issues, statuses, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);

    function applyFilter(field: string, value: string) {
        router.get('/operations/inventory/issues', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Inventory Issues</h1>
                    <p className="text-sm text-gray-500 mt-1">{issues.total} issue{issues.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/inventory" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Dashboard</Link>
                    {can('inventory.issue.create') && (
                        <Link href="/operations/inventory/issues/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">New Issue</Link>
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
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {issues.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">No issues found.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Lines</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Issued At</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {issues.data.map((iss) => (
                                    <tr key={iss.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 font-mono text-xs text-gray-700">{iss.issue_number}</td>
                                        <td className="px-6 py-4 text-gray-700">{iss.department?.name ?? iss.department_id ?? '—'}</td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${statusColors[iss.status.value] ?? 'bg-gray-100 text-gray-600'}`}>
                                                {iss.status.label}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right text-gray-600">{iss.lines_count ?? '—'}</td>
                                        <td className="px-6 py-4 text-gray-500 text-xs">{iss.issued_at ?? '—'}</td>
                                        <td className="px-6 py-4 text-right">
                                            <Link href={`/operations/inventory/issues/${iss.id}`} className="text-blue-600 hover:text-blue-800 text-sm">View</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {issues.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {issues.current_page} of {issues.last_page}</span>
                        <div className="flex gap-1">
                            {issues.links.map((link, i) => (
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
