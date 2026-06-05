import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { Guest, PaginatedData, EnumOption } from '@/Types';

interface Props {
    guests:      PaginatedData<Guest>;
    guest_types: EnumOption[];
    filters:     { search?: string; guest_type?: string };
}

function guestTypeBadge(guestType: EnumOption) {
    const classes: Record<string, string> = {
        individual: 'bg-gray-100 text-gray-600',
        corporate:  'bg-blue-100 text-blue-700',
        group:      'bg-purple-100 text-purple-700',
        vip:        'bg-yellow-100 text-yellow-700',
    };
    const cls = classes[String(guestType.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {guestType.label}
        </span>
    );
}

export default function GuestIndex({ guests, guest_types, filters }: Props) {
    function applyFilter(field: string, value: string) {
        router.get('/operations/pms/guests', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Guests</h1>
                    <p className="text-sm text-gray-500 mt-1">{guests.total} guest{guests.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/pms" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Dashboard
                    </Link>
                    <Link href="/operations/pms/guests/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                        New Guest
                    </Link>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap gap-3 mb-4">
                <input
                    type="text"
                    value={filters.search ?? ''}
                    onChange={(e) => applyFilter('search', e.target.value)}
                    placeholder="Search by name, email, code…"
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-64"
                />
                <select
                    value={filters.guest_type ?? ''}
                    onChange={(e) => applyFilter('guest_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Types</option>
                    {guest_types.map((t) => (
                        <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {guests.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No guests found.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">VIP</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {guests.data.map((guest) => (
                                <tr key={guest.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 font-mono text-xs text-gray-600">{guest.guest_code}</td>
                                    <td className="px-6 py-4 font-medium text-gray-900">{guest.full_name}</td>
                                    <td className="px-6 py-4 text-gray-600">{guest.email ?? <span className="text-gray-400">—</span>}</td>
                                    <td className="px-6 py-4 text-gray-600">{guest.phone ?? <span className="text-gray-400">—</span>}</td>
                                    <td className="px-6 py-4">{guestTypeBadge(guest.guest_type)}</td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {guest.vip_level ? (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700">
                                                VIP {guest.vip_level}
                                            </span>
                                        ) : (
                                            <span className="text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 text-right">
                                        <Link
                                            href={`/operations/pms/guests/${guest.id}`}
                                            className="text-blue-600 hover:text-blue-800 text-sm"
                                        >
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                )}

                {guests.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {guests.current_page} of {guests.last_page}</span>
                        <div className="flex gap-1">
                            {guests.links.map((link, i) => (
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${link.active ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
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
