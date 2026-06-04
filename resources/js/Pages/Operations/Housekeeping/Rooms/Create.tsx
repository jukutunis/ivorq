import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface Props {
    room_types:           EnumOption[];
    cleanliness_statuses: EnumOption[];
    occupancy_statuses:   EnumOption[];
}

export default function RoomCreate({ room_types, cleanliness_statuses, occupancy_statuses }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        room_number: '',
        room_name:   '',
        room_type:   '',
        floor:       '',
        building:    '',
        zone_id:     '',
        notes:       '',
        is_active:   true,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/rooms');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/rooms" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Rooms
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Add Room</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Room Number <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.room_number}
                                onChange={(e) => setData('room_number', e.target.value)}
                                placeholder="e.g. 101"
                                maxLength={20}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.room_number && (
                                <p className="text-red-600 text-xs mt-1">{errors.room_number}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Room Name <span className="text-gray-400 font-normal">(optional)</span>
                            </label>
                            <input
                                type="text"
                                value={data.room_name}
                                onChange={(e) => setData('room_name', e.target.value)}
                                placeholder="e.g. Garden View Deluxe"
                                maxLength={255}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.room_name && (
                                <p className="text-red-600 text-xs mt-1">{errors.room_name}</p>
                            )}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Room Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.room_type}
                                onChange={(e) => setData('room_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select type —</option>
                                {room_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.room_type && (
                                <p className="text-red-600 text-xs mt-1">{errors.room_type}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Zone ID <span className="text-gray-400 font-normal">(optional)</span>
                            </label>
                            <input
                                type="text"
                                value={data.zone_id}
                                onChange={(e) => setData('zone_id', e.target.value)}
                                placeholder="Zone ULID"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.zone_id && (
                                <p className="text-red-600 text-xs mt-1">{errors.zone_id}</p>
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
                                placeholder="e.g. 1"
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
                                placeholder="e.g. Main Wing"
                                maxLength={100}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.building && (
                                <p className="text-red-600 text-xs mt-1">{errors.building}</p>
                            )}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={3}
                            placeholder="Optional notes about this room…"
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
                        {processing ? 'Creating…' : 'Create Room'}
                    </button>
                    <Link
                        href="/operations/rooms"
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
