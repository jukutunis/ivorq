import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { PaginatedData, EnumOption, PageProps } from '@/Types';

interface ReceiptLine {
    id: string; item_id: string; location_id: string;
    quantity: number; unit_cost: number; total_value: number; notes: string | null;
    item?: { id: string; item_code: string; name: string } | null;
    location?: { id: string; location_code: string; name: string } | null;
}
interface Receipt {
    id: string; receipt_number: string; supplier_name: string | null;
    external_reference: string | null; status: EnumOption;
    received_at: string | null; remarks: string | null;
    posted_at: string | null; cancelled_at: string | null;
    created_at: string; lines_count?: number;
    lines?: ReceiptLine[];
}

interface Props {
    receipts: PaginatedData<Receipt>;
    statuses: EnumOption[];
    filters:  { status?: string; supplier_name?: string };
}

const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-600',
    posted:    'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-600',
};

export default function ReceiptIndex({ receipts, statuses, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(p);

    function applyFilter(field: string, value: string) {
        router.get('/operations/inventory/receipts', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Inventory Receipts</h1>
                    <p className="text-sm text-gray-500 mt-1">{receipts.total} receipt{receipts.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/inventory" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">Dashboard</Link>
                    {can('inventory.receipt.create') && (
                        <Link href="/operations/inventory/receipts/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">New Receipt</Link>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap gap-3 mb-4">
                <input type="text" value={filters.supplier_name ?? ''}
                    onChange={(e) => applyFilter('supplier_name', e.target.value)}
                    placeholder="Search by supplier…"
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-56" />
                <select value={filters.status ?? ''}
                    onChange={(e) => applyFilter('status', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Statuses</option>
                    {statuses.map((s) => <option key={String(s.value)} value={String(s.value)}>{s.label}</option>)}
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {receipts.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">No receipts found.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Ref</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Lines</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Received At</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {receipts.data.map((r) => (
                                    <tr key={r.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 font-mono text-xs text-gray-700">{r.receipt_number}</td>
                                        <td className="px-6 py-4 font-medium text-gray-900">{r.supplier_name ?? <span className="text-gray-400">—</span>}</td>
                                        <td className="px-6 py-4 text-gray-500 text-xs">{r.external_reference ?? '—'}</td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${statusColors[r.status.value] ?? 'bg-gray-100 text-gray-600'}`}>
                                                {r.status.label}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right text-gray-600">{r.lines_count ?? '—'}</td>
                                        <td className="px-6 py-4 text-gray-500 text-xs">{r.received_at ?? '—'}</td>
                                        <td className="px-6 py-4 text-right">
                                            <Link href={`/operations/inventory/receipts/${r.id}`} className="text-blue-600 hover:text-blue-800 text-sm">View</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {receipts.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {receipts.current_page} of {receipts.last_page}</span>
                        <div className="flex gap-1">
                            {receipts.links.map((link, i) => (
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
