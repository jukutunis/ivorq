import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { Reservation, Stay, Folio, EnumOption } from '@/Types';
import { useState } from 'react';
import axios from 'axios';

interface Props {
    reservation: Reservation;
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
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function folioStatusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        open:   'bg-green-100 text-green-700',
        closed: 'bg-gray-100 text-gray-600',
        void:   'bg-red-100 text-red-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

export default function ReservationShow({ reservation }: Props) {
    const [loading, setLoading]           = useState(false);
    const [showAssignRoom, setShowAssignRoom] = useState(false);
    const [roomId, setRoomId]             = useState('');
    const [cancelReason, setCancelReason] = useState('');
    const [showCancel, setShowCancel]     = useState(false);

    const statusVal = String(reservation.status.value);
    const stays     = reservation.stays ?? [];
    const folios    = reservation.folios ?? [];
    const activeStay = stays.find((s) => String(s.status.value) === 'checked_in') ?? null;

    function doAction(url: string, payload: Record<string, unknown> = {}) {
        if (loading) return;
        setLoading(true);
        axios
            .post(url, payload)
            .then(() => router.reload())
            .finally(() => setLoading(false));
    }

    function confirm_action(action: () => void, message: string) {
        if (confirm(message)) action();
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/reservations" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Reservations
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{reservation.reservation_number}</h1>
                    {statusBadge(reservation.status)}
                </div>
                <Link
                    href={`/operations/pms/reservations/${reservation.id}/edit`}
                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                >
                    Edit
                </Link>
            </div>

            {/* Reservation Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Reservation Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Guest</p>
                        <p className="text-sm text-gray-700">
                            {reservation.primary_guest ? (
                                <Link href={`/operations/pms/guests/${reservation.primary_guest.id}`} className="text-blue-600 hover:text-blue-800">
                                    {reservation.primary_guest.full_name}
                                </Link>
                            ) : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Arrival</p>
                        <p className="text-sm text-gray-700">{reservation.arrival_date}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Departure</p>
                        <p className="text-sm text-gray-700">{reservation.departure_date}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Nights</p>
                        <p className="text-sm text-gray-700">{reservation.nights}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Adults / Children</p>
                        <p className="text-sm text-gray-700">{reservation.adults} / {reservation.children}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Room Type</p>
                        <p className="text-sm text-gray-700">{reservation.reserved_room_type.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Source</p>
                        <p className="text-sm text-gray-700">{reservation.reservation_source.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Rate Plan</p>
                        <p className="text-sm text-gray-700">
                            {reservation.rate_plan ? (
                                <Link href={`/operations/pms/rate-plans/${reservation.rate_plan.id}`} className="text-blue-600 hover:text-blue-800">
                                    {reservation.rate_plan.rate_name}
                                </Link>
                            ) : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Assigned Room</p>
                        <p className="text-sm text-gray-700">
                            {reservation.assigned_room ? (
                                <span>{reservation.assigned_room.room_number}</span>
                            ) : <span className="text-gray-400">Not assigned</span>}
                        </p>
                    </div>
                </div>

                {reservation.remarks && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Remarks</p>
                        <p className="text-sm text-gray-700">{reservation.remarks}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {reservation.created_at}
                </div>
            </div>

            {/* Front Desk Actions */}
            {!['checked_out', 'cancelled', 'no_show'].includes(statusVal) && (
                <div className="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Front Desk Actions</h2>
                    <div className="flex flex-wrap gap-3">

                        {/* Confirm */}
                        {statusVal === 'tentative' && (
                            <button
                                onClick={() => confirm_action(
                                    () => doAction(`/operations/pms/reservations/${reservation.id}/confirm`),
                                    'Confirm this reservation?'
                                )}
                                disabled={loading}
                                className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                            >
                                {loading ? '…' : 'Confirm'}
                            </button>
                        )}

                        {/* Assign Room */}
                        {['tentative', 'confirmed', 'waitlisted'].includes(statusVal) && (
                            <button
                                onClick={() => setShowAssignRoom(!showAssignRoom)}
                                className="bg-gray-600 text-white px-4 py-2 rounded text-sm hover:bg-gray-700"
                            >
                                Assign Room
                            </button>
                        )}

                        {/* Check In */}
                        {statusVal === 'confirmed' && (
                            <button
                                onClick={() => confirm_action(
                                    () => doAction(`/operations/pms/reservations/${reservation.id}/check-in`),
                                    'Check in this guest?'
                                )}
                                disabled={loading}
                                className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700 disabled:opacity-60"
                            >
                                {loading ? '…' : 'Check In'}
                            </button>
                        )}

                        {/* Check Out (from active stay) */}
                        {statusVal === 'checked_in' && activeStay && (
                            <button
                                onClick={() => confirm_action(
                                    () => doAction(`/operations/pms/stays/${activeStay.id}/check-out`),
                                    'Check out this guest?'
                                )}
                                disabled={loading}
                                className="bg-purple-600 text-white px-4 py-2 rounded text-sm hover:bg-purple-700 disabled:opacity-60"
                            >
                                {loading ? '…' : 'Check Out'}
                            </button>
                        )}

                        {/* No Show */}
                        {statusVal === 'confirmed' && (
                            <button
                                onClick={() => confirm_action(
                                    () => doAction(`/operations/pms/reservations/${reservation.id}/no-show`),
                                    'Mark this reservation as No Show?'
                                )}
                                disabled={loading}
                                className="bg-orange-100 text-orange-700 px-4 py-2 rounded text-sm hover:bg-orange-200 disabled:opacity-60"
                            >
                                {loading ? '…' : 'No Show'}
                            </button>
                        )}

                        {/* Cancel */}
                        {['tentative', 'confirmed', 'waitlisted'].includes(statusVal) && (
                            <button
                                onClick={() => setShowCancel(!showCancel)}
                                className="bg-red-100 text-red-700 px-4 py-2 rounded text-sm hover:bg-red-200"
                            >
                                Cancel Reservation
                            </button>
                        )}
                    </div>

                    {/* Assign Room Form */}
                    {showAssignRoom && (
                        <div className="mt-4 pt-4 border-t border-gray-100">
                            <p className="text-sm font-medium text-gray-700 mb-3">Assign Room</p>
                            <div className="flex gap-3 items-end">
                                <div className="flex-1">
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Room ID (ULID)</label>
                                    <input
                                        type="text"
                                        value={roomId}
                                        onChange={(e) => setRoomId(e.target.value)}
                                        placeholder="Room ULID"
                                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                                <button
                                    onClick={() => {
                                        doAction(`/operations/pms/reservations/${reservation.id}/assign-room`, { room_id: roomId });
                                        setShowAssignRoom(false);
                                        setRoomId('');
                                    }}
                                    disabled={!roomId || loading}
                                    className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                                >
                                    Assign
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Cancel Form */}
                    {showCancel && (
                        <div className="mt-4 pt-4 border-t border-gray-100">
                            <p className="text-sm font-medium text-gray-700 mb-3">Cancel Reservation</p>
                            <div className="flex gap-3 items-end">
                                <div className="flex-1">
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Reason (optional)</label>
                                    <input
                                        type="text"
                                        value={cancelReason}
                                        onChange={(e) => setCancelReason(e.target.value)}
                                        placeholder="Cancellation reason…"
                                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                                <button
                                    onClick={() => {
                                        doAction(`/operations/pms/reservations/${reservation.id}/cancel`, { reason: cancelReason });
                                        setShowCancel(false);
                                    }}
                                    disabled={loading}
                                    className="bg-red-600 text-white px-4 py-2 rounded text-sm hover:bg-red-700 disabled:opacity-60"
                                >
                                    Confirm Cancel
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Stays */}
            {stays.length > 0 && (
                <div className="bg-white rounded-lg shadow mb-6">
                    <div className="px-6 py-4 border-b border-gray-100">
                        <h2 className="text-sm font-semibold text-gray-700">
                            Stays
                            <span className="ml-2 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs">{stays.length}</span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Check-In</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Check-Out</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {stays.map((stay) => (
                                <tr key={stay.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 text-gray-700">{stay.room?.room_number ?? '—'}</td>
                                    <td className="px-6 py-4 text-gray-600 text-xs">{stay.check_in_at}</td>
                                    <td className="px-6 py-4 text-gray-600 text-xs">{stay.check_out_at ?? '—'}</td>
                                    <td className="px-6 py-4">
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                                            {stay.status.label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                </div>
            )}

            {/* Folios */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-700">
                        Folios
                        {folios.length > 0 && (
                            <span className="ml-2 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs">{folios.length}</span>
                        )}
                    </h2>
                    <Link
                        href={`/operations/pms/reservations/${reservation.id}/folios`}
                        method="post"
                        as="button"
                        className="text-blue-600 hover:text-blue-800 text-xs"
                    >
                        New Folio
                    </Link>
                </div>

                {folios.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">No folios yet.</div>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Charges</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {folios.map((folio) => (
                                <tr key={folio.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 font-mono text-xs text-gray-600">{folio.folio_number}</td>
                                    <td className="px-6 py-4">{folioStatusBadge(folio.status)}</td>
                                    <td className="px-6 py-4 text-gray-700">{folio.currency} {folio.total_charges.toFixed(2)}</td>
                                    <td className="px-6 py-4 text-gray-700 font-medium">{folio.currency} {folio.balance.toFixed(2)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={`/operations/pms/folios/${folio.id}`} className="text-blue-600 hover:text-blue-800 text-sm">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
