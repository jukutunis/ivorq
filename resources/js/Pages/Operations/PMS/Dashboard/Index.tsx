import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { Reservation, EnumOption } from '@/Types';

interface Stats {
    arrivals_today:   number;
    departures_today: number;
    in_house_count:   number;
    available_rooms:  number;
}

interface Props {
    stats:            Stats;
    arrivals_today:   { data: Reservation[] };
    departures_today: { data: Reservation[] };
}

function reservationStatusBadge(status: EnumOption) {
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

function StatCard({
    label,
    value,
    href,
    colorClass,
}: {
    label: string;
    value: number;
    href: string;
    colorClass: string;
}) {
    return (
        <Link href={href} className="bg-white rounded-lg shadow p-6 hover:shadow-md transition-shadow">
            <p className="text-sm text-gray-500 mb-1">{label}</p>
            <p className={`text-3xl font-bold ${colorClass}`}>{value}</p>
        </Link>
    );
}

export default function PmsDashboard({ stats, arrivals_today, departures_today }: Props) {
    const arrivals   = arrivals_today?.data ?? [];
    const departures = departures_today?.data ?? [];

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">PMS</h1>
                    <p className="text-sm text-gray-500 mt-1">Front Desk Overview</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/pms/guests" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Guests
                    </Link>
                    <Link href="/operations/pms/reservations" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Reservations
                    </Link>
                    <Link href="/operations/pms/room-blocks" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Room Blocks
                    </Link>
                    <Link href="/operations/pms/folios" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Folios
                    </Link>
                    <Link href="/operations/pms/rate-plans" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Rate Plans
                    </Link>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
                <StatCard
                    label="Arrivals Today"
                    value={stats.arrivals_today}
                    href="/operations/pms/reservations?status=confirmed"
                    colorClass="text-blue-600"
                />
                <StatCard
                    label="Departures Today"
                    value={stats.departures_today}
                    href="/operations/pms/reservations?status=checked_in"
                    colorClass="text-purple-600"
                />
                <StatCard
                    label="In-House"
                    value={stats.in_house_count}
                    href="/operations/pms/reservations?status=checked_in"
                    colorClass="text-green-600"
                />
                <StatCard
                    label="Available Rooms"
                    value={stats.available_rooms}
                    href="/operations/rooms"
                    colorClass="text-gray-900"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Arrivals Today */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">Arrivals Today</h2>
                        <Link
                            href="/operations/pms/reservations/create"
                            className="text-blue-600 hover:text-blue-800 text-xs"
                        >
                            New reservation
                        </Link>
                    </div>

                    {arrivals.length === 0 ? (
                        <div className="px-6 py-8 text-center text-gray-400 text-sm">
                            No arrivals today.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Reservation</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {arrivals.map((res) => (
                                    <tr key={res.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/operations/pms/reservations/${res.id}`}
                                                className="font-medium text-gray-900 hover:text-blue-600 text-xs font-mono"
                                            >
                                                {res.reservation_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-600">
                                            {res.primary_guest?.full_name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">{reservationStatusBadge(res.status)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="px-6 py-3 border-t border-gray-100">
                        <Link href="/operations/pms/reservations" className="text-xs text-blue-600 hover:text-blue-800">
                            View all reservations →
                        </Link>
                    </div>
                </div>

                {/* Departures Today */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">Departures Today</h2>
                        <Link
                            href="/operations/pms/folios"
                            className="text-blue-600 hover:text-blue-800 text-xs"
                        >
                            All folios
                        </Link>
                    </div>

                    {departures.length === 0 ? (
                        <div className="px-6 py-8 text-center text-gray-400 text-sm">
                            No departures today.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Reservation</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {departures.map((res) => (
                                    <tr key={res.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/operations/pms/reservations/${res.id}`}
                                                className="font-medium text-gray-900 hover:text-blue-600 text-xs font-mono"
                                            >
                                                {res.reservation_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-600">
                                            {res.primary_guest?.full_name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">{reservationStatusBadge(res.status)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="px-6 py-3 border-t border-gray-100">
                        <Link href="/operations/pms/reservations?status=checked_in" className="text-xs text-blue-600 hover:text-blue-800">
                            View checked-in guests →
                        </Link>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
