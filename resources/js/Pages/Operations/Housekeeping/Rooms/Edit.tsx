import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { Room, EnumOption } from '@/Types';

interface Props {
    room:       Room;
    room_types: EnumOption[];
}

export default function RoomEdit({ room, room_types }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        room_name: room.room_name ?? '',
        room_type: String(room.room_type.value),
        floor:     room.floor ?? '',
        building:  room.building ?? '',
        zone_id:   room.zone_id ?? '',
        notes:     room.notes ?? '',
        is_active: room.is_active,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/rooms/${room.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/rooms" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Rooms
                </Link>
                <span className="text-gray-300">/</span>
                <Link
                    href={`/operations/rooms/${room.id}`}
                    className="text-sm text-gray-500 hover:text-gray-700"
                >
                    {room.room_number}
                </Link>
            </div>

            <div className="flex items-center gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Edit Room {room.room_number}</h1>
            </div>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="flex items-center gap-2 p-3 bg-gray-50 rounded border border-gray-200 text-sm text-gray-600">
                        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Room number cannot be changed. Use the room detail page to update cleanliness and occupancy status.
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Room Number
                        </label>
                        <input
                            type="text"
                            value={room.room_number}
                            disabled
                            className="border border-gray-200 rounded px-3 py-2 text-sm w-full bg-gray-50 text-gray-500 cursor-not-allowed"
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Room Name</label>
                            <input
                                type="text"
                                value={data.room_name}
                                onChange={(e) => setData('room_name', e.target.value)}
                                maxLength={255}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.room_name && (
                                <p className="text-red-600 text-xs mt-1">{errors.room_name}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Room Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.room_type}
                                onChange={(e) => setData('room_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {room_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.room_type && (
                                <p className="text-red-600 text-xs mt-1">{errors.room_type}</p>
                            )}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Floor</label>
                            <input
                                type="text"
                                value={data.floor}
                                onChange={(e) => setData('floor', e.target.value)}
                                maxLength={10}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.floor && (
                                <p className="text-red-600 text-xs mt-1">{errors.floor}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Building</label>
                            <input
                                type="text"
                                value={data.building}
                                onChange={(e) => setData('building', e.target.value)}
                                maxLength={100}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.building && (
                                <p className="text-red-600 text-xs mt-1">{errors.building}</p>
                            )}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Zone ID</label>
                        <input
                            type="text"
                            value={data.zone_id}
                            onChange={(e) => setData('zone_id', e.target.value)}
                            placeholder="Zone ULID (optional)"
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.zone_id && (
                            <p className="text-red-600 text-xs mt-1">{errors.zone_id}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={3}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.notes && (
                            <p className="text-red-600 text-xs mt-1">{errors.notes}</p>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id="is_active"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="rounded border-gray-300"
                        />
                        <label htmlFor="is_active" className="text-sm text-gray-700">Active room</label>
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Save Changes'}
                    </button>
                    <Link
                        href={`/operations/rooms/${room.id}`}
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                    <Link
                        href={`/operations/rooms/${room.id}`}
                        method="delete"
                        as="button"
                        className="ml-auto text-red-600 hover:text-red-800 text-sm px-3 py-2"
                        onBefore={() => confirm('Delete this room? This action cannot be undone.')}
                    >
                        Delete Room
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
