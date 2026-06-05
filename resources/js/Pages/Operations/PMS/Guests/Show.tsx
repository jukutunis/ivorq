import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { Guest, EnumOption } from '@/Types';

interface Props {
    guest: Guest;
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
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {guestType.label}
        </span>
    );
}

export default function GuestShow({ guest }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/guests" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Guests
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{guest.full_name}</h1>
                    {guestTypeBadge(guest.guest_type)}
                    {guest.vip_level && (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700">
                            VIP {guest.vip_level}
                        </span>
                    )}
                </div>
                <Link
                    href={`/operations/pms/guests/${guest.id}/edit`}
                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                >
                    Edit
                </Link>
            </div>

            {/* Guest Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Guest Profile</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Guest Code</p>
                        <p className="text-sm font-mono text-gray-700">{guest.guest_code}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Email</p>
                        <p className="text-sm text-gray-700">{guest.email ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Phone</p>
                        <p className="text-sm text-gray-700">{guest.phone ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Nationality</p>
                        <p className="text-sm text-gray-700">{guest.nationality ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">ID Type</p>
                        <p className="text-sm text-gray-700">{guest.id_type ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">ID Number</p>
                        <p className="text-sm text-gray-700">{guest.id_number ?? '—'}</p>
                    </div>
                </div>

                {guest.notes && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Notes</p>
                        <p className="text-sm text-gray-700">{guest.notes}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {guest.created_at}
                </div>
            </div>

            {/* Reservations */}
            {guest.reservations && (
                <div className="bg-white rounded-lg shadow mb-6">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">
                            Reservations
                            {guest.reservations.length > 0 && (
                                <span className="ml-2 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs">
                                    {guest.reservations.length}
                                </span>
                            )}
                        </h2>
                        <Link
                            href={`/operations/pms/reservations/create`}
                            className="text-blue-600 hover:text-blue-800 text-xs"
                        >
                            New reservation
                        </Link>
                    </div>

                    {guest.reservations.length === 0 ? (
                        <div className="px-6 py-8 text-center text-gray-400 text-sm">No reservations yet.</div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Arrival</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Departure</th>
                                    <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {guest.reservations.map((res) => (
                                    <tr key={res.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 font-mono text-xs text-gray-600">{res.reservation_number}</td>
                                        <td className="px-6 py-4 text-gray-700">{res.arrival_date}</td>
                                        <td className="px-6 py-4 text-gray-700">{res.departure_date}</td>
                                        <td className="px-6 py-4">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                                                {res.status.label}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <Link href={`/operations/pms/reservations/${res.id}`} className="text-blue-600 hover:text-blue-800 text-sm">
                                                View
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
