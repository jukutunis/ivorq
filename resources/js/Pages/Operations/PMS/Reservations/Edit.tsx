import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { Reservation, EnumOption } from '@/Types';

interface Props {
    reservation: Reservation;
    room_types:  EnumOption[];
    sources:     EnumOption[];
}

export default function ReservationEdit({ reservation, room_types, sources }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        primary_guest_id:   reservation.primary_guest_id,
        rate_plan_id:        reservation.rate_plan_id ?? '',
        arrival_date:        reservation.arrival_date,
        departure_date:      reservation.departure_date,
        adults:              String(reservation.adults),
        children:            String(reservation.children),
        reservation_source:  String(reservation.reservation_source.value),
        reserved_room_type:  String(reservation.reserved_room_type.value),
        assigned_room_id:    reservation.assigned_room_id ?? '',
        remarks:             reservation.remarks ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/pms/reservations/${reservation.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/reservations" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Reservations
                </Link>
                <span className="text-gray-300">/</span>
                <Link href={`/operations/pms/reservations/${reservation.id}`} className="text-sm text-gray-500 hover:text-gray-700">
                    {reservation.reservation_number}
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Reservation</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="flex items-center gap-2 p-3 bg-gray-50 rounded border border-gray-200 text-sm text-gray-600">
                        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        To change reservation status, use the Front Desk actions on the reservation detail page.
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Primary Guest ID <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.primary_guest_id}
                            onChange={(e) => setData('primary_guest_id', e.target.value)}
                            placeholder="Guest ULID"
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.primary_guest_id && <p className="text-red-600 text-xs mt-1">{errors.primary_guest_id}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Arrival Date <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="date"
                                value={data.arrival_date}
                                onChange={(e) => setData('arrival_date', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.arrival_date && <p className="text-red-600 text-xs mt-1">{errors.arrival_date}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Departure Date <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="date"
                                value={data.departure_date}
                                onChange={(e) => setData('departure_date', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.departure_date && <p className="text-red-600 text-xs mt-1">{errors.departure_date}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Adults</label>
                            <input
                                type="number"
                                value={data.adults}
                                onChange={(e) => setData('adults', e.target.value)}
                                min={1}
                                max={20}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.adults && <p className="text-red-600 text-xs mt-1">{errors.adults}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Children</label>
                            <input
                                type="number"
                                value={data.children}
                                onChange={(e) => setData('children', e.target.value)}
                                min={0}
                                max={20}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.children && <p className="text-red-600 text-xs mt-1">{errors.children}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Room Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.reserved_room_type}
                                onChange={(e) => setData('reserved_room_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {room_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.reserved_room_type && <p className="text-red-600 text-xs mt-1">{errors.reserved_room_type}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Source</label>
                            <select
                                value={data.reservation_source}
                                onChange={(e) => setData('reservation_source', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {sources.map((s) => (
                                    <option key={String(s.value)} value={String(s.value)}>{s.label}</option>
                                ))}
                            </select>
                            {errors.reservation_source && <p className="text-red-600 text-xs mt-1">{errors.reservation_source}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Rate Plan ID</label>
                            <input
                                type="text"
                                value={data.rate_plan_id}
                                onChange={(e) => setData('rate_plan_id', e.target.value)}
                                placeholder="Rate Plan ULID (optional)"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.rate_plan_id && <p className="text-red-600 text-xs mt-1">{errors.rate_plan_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Assigned Room ID</label>
                            <input
                                type="text"
                                value={data.assigned_room_id}
                                onChange={(e) => setData('assigned_room_id', e.target.value)}
                                placeholder="Room ULID (optional)"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.assigned_room_id && <p className="text-red-600 text-xs mt-1">{errors.assigned_room_id}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                        <textarea
                            value={data.remarks}
                            onChange={(e) => setData('remarks', e.target.value)}
                            rows={3}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.remarks && <p className="text-red-600 text-xs mt-1">{errors.remarks}</p>}
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
                    <Link href={`/operations/pms/reservations/${reservation.id}`} className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                    <Link
                        href={`/operations/pms/reservations/${reservation.id}`}
                        method="delete"
                        as="button"
                        className="ml-auto text-red-600 hover:text-red-800 text-sm px-3 py-2"
                        onBefore={() => confirm('Delete this reservation? This action cannot be undone.')}
                    >
                        Delete Reservation
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
