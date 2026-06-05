import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface Props {
    room_types: EnumOption[];
    sources:    EnumOption[];
}

export default function ReservationCreate({ room_types, sources }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        primary_guest_id:   '',
        rate_plan_id:        '',
        arrival_date:        '',
        departure_date:      '',
        adults:              '1',
        children:            '0',
        reservation_source:  '',
        reserved_room_type:  '',
        assigned_room_id:    '',
        remarks:             '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/pms/reservations');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/reservations" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Reservations
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Reservation</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

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
                        <p className="text-xs text-gray-400 mt-1">
                            Find the guest ULID from the{' '}
                            <Link href="/operations/pms/guests" className="text-blue-500 hover:text-blue-700">guests list</Link>.
                        </p>
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
                                <option value="">— Select room type —</option>
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
                                <option value="">— Select source —</option>
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
                            placeholder="Optional remarks…"
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
                        {processing ? 'Creating…' : 'Create Reservation'}
                    </button>
                    <Link href="/operations/pms/reservations" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
