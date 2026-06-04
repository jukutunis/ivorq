import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { Room, PaginatedData, EnumOption } from '@/Types';

interface Props {
    rooms: PaginatedData<Room>;
}

function cleanlinessbadge(status: EnumOption) {
    const classes: Record<string, string> = {
        dirty:     'bg-red-100 text-red-700',
        clean:     'bg-green-100 text-green-700',
        inspected: 'bg-blue-100 text-blue-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function occupancyBadge(status: EnumOption | null) {
    if (!status) {
        return <span className="text-gray-400 text-xs">—</span>;
    }
    const classes: Record<string, string> = {
        vacant:   'bg-gray-100 text-gray-600',
        occupied: 'bg-amber-100 text-amber-700',
        blocked:  'bg-orange-100 text-orange-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function roomTypeBadge(roomType: EnumOption) {
    const classes: Record<string, string> = {
        standard:  'bg-gray-100 text-gray-700',
        deluxe:    'bg-blue-100 text-blue-700',
        suite:     'bg-purple-100 text-purple-700',
        villa:     'bg-teal-100 text-teal-700',
        dormitory: 'bg-slate-100 text-slate-700',
        custom:    'bg-orange-100 text-orange-700',
    };
    const cls = classes[String(roomType.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {roomType.label}
        </span>
    );
}

export default function RoomIndex({ rooms }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Rooms</h1>
                    <p className="text-sm text-gray-500 mt-1">{rooms.total} room{rooms.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex gap-2">
                    <Link
                        href="/operations/housekeeping"
                        className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Dashboard
                    </Link>
                    <Link
                        href="/operations/rooms/create"
                        className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700"
                    >
                        Add Room
                    </Link>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {rooms.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No rooms found. Add the first room for this property.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Floor</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Zone</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Cleanliness</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Occupancy</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rooms.data.map((room) => (
                                <tr key={room.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4">
                                        <p className="font-medium text-gray-900">{room.room_number}</p>
                                        {room.room_name && (
                                            <p className="text-xs text-gray-500">{room.room_name}</p>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">{roomTypeBadge(room.room_type)}</td>
                                    <td className="px-6 py-4 text-gray-600">{room.floor ?? <span className="text-gray-400">—</span>}</td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {room.zone ? (
                                            <span className="text-xs font-mono">{room.zone.zone_code}</span>
                                        ) : (
                                            <span className="text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">{cleanlinessbadge(room.cleanliness_status)}</td>
                                    <td className="px-6 py-4">{occupancyBadge(room.occupancy_status)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link
                                            href={`/operations/rooms/${room.id}`}
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

                {rooms.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {rooms.current_page} of {rooms.last_page}</span>
                        <div className="flex gap-1">
                            {rooms.links.map((link, i) => (
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${
                                            link.active
                                                ? 'bg-blue-600 text-white border-blue-600'
                                                : 'border-gray-300 hover:bg-gray-50'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span
                                        key={i}
                                        className="px-3 py-1 rounded border border-gray-200 text-xs text-gray-400"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
