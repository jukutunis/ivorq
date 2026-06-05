import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { Reservation, PaginatedData, EnumOption } from '@/Types';

interface Props {
    reservations: PaginatedData<Reservation>;
    statuses:     EnumOption[];
    room_types:   EnumOption[];
    sources:      EnumOption[];
    filters:      { status?: string; reservation_source?: string; reserved_room_type?: string };
}

function statusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        tentative:   'bg-gray-100 text-gray-600',
        confirmed:   'bg-blue-100 text-blue-700',
        waitlisted:  'bg-yellow-100 text-yellow-700',
        checked_in:  'bg-green-100 text-green-700',
        checked_out: 'bg-purple-100 text-purple-700',
        cancelled:   'bg-red-100 text-red-700',
        no_show:     'bg-orange-100 text-orange-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

export default function ReservationIndex({ reservations, statuses, room_types, sources, filters }: Props) {
    function applyFilter(field: string, value: string) {
        router.get('/operations/pms/reservations', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Reservations</h1>
                    <p className="text-sm text-gray-500 mt-1">{reservations.total} reservation{reservations.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex gap-2">
                    <Link href="/operations/pms" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Dashboard
                    </Link>
                    <Link href="/operations/pms/reservations/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                        New Reservation
                    </Link>
                </div>
            </div>

            {/* Filters */}
            <div className="flex gap-3 mb-4 flex-wrap">
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => applyFilter('status', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Statuses</option>
                    {statuses.map((s) => (
                        <option key={String(s.value)} value={String(s.value)}>{s.label}</option>
                    ))}
                </select>
                <select
                    value={filters.reservation_source ?? ''}
                    onChange={(e) => applyFilter('reservation_source', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Sources</option>
                    {sources.map((s) => (
                        <option key={String(s.value)} value={String(s.value)}>{s.label}</option>
                    ))}
                </select>
                <select
                    value={filters.reserved_room_type ?? ''}
                    onChange={(e) => applyFilter('reserved_room_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Room Types</option>
                    {room_types.map((t) => (
                        <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {reservations.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No reservations found.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Arrival</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Departure</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Room Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {reservations.data.map((res) => (
                                <tr key={res.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 font-mono text-xs text-gray-600">{res.reservation_number}</td>
                                    <td className="px-6 py-4 font-medium text-gray-900">
                                        {res.primary_guest?.full_name ?? <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4 text-gray-600">{res.arrival_date}</td>
                                    <td className="px-6 py-4 text-gray-600">{res.departure_date}</td>
                                    <td className="px-6 py-4 text-gray-600">{res.reserved_room_type.label}</td>
                                    <td className="px-6 py-4">{statusBadge(res.status)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link
                                            href={`/operations/pms/reservations/${res.id}`}
                                            className="text-blue-600 hover:text-blue-800 text-sm"
                                        >
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {reservations.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {reservations.current_page} of {reservations.last_page}</span>
                        <div className="flex gap-1">
                            {reservations.links.map((link, i) => (
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
