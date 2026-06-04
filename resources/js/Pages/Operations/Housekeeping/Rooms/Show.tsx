import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { Room, EnumOption } from '@/Types';
import { useState } from 'react';

interface Props {
    room: Room;
}

function cleanlinessBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        dirty:     'bg-red-100 text-red-700',
        clean:     'bg-green-100 text-green-700',
        inspected: 'bg-blue-100 text-blue-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function occupancyBadge(status: EnumOption | null) {
    if (!status) return <span className="text-gray-400 text-xs italic">Not tracked</span>;
    const classes: Record<string, string> = {
        vacant:   'bg-gray-100 text-gray-600',
        occupied: 'bg-amber-100 text-amber-700',
        blocked:  'bg-orange-100 text-orange-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

export default function RoomShow({ room }: Props) {
    const [showCleanForm, setShowCleanForm]   = useState(false);
    const [showOccupForm, setShowOccupForm]   = useState(false);
    const [cleanTarget, setCleanTarget]       = useState('');
    const [occupTarget, setOccupTarget]       = useState('');
    const [remarks, setRemarks]               = useState('');

    const histories   = room.status_histories ?? [];
    const inspections = room.inspections ?? [];

    function submitCleanliness(e: React.FormEvent) {
        e.preventDefault();
        router.post(`/operations/rooms/${room.id}/cleanliness`, {
            cleanliness_status: cleanTarget,
            remarks,
        }, { preserveScroll: true, onSuccess: () => { setShowCleanForm(false); setRemarks(''); } });
    }

    function submitOccupancy(e: React.FormEvent) {
        e.preventDefault();
        router.post(`/operations/rooms/${room.id}/occupancy`, {
            occupancy_status: occupTarget,
            remarks,
        }, { preserveScroll: true, onSuccess: () => { setShowOccupForm(false); setRemarks(''); } });
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/rooms" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Rooms
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3">
                    <h1 className="text-2xl font-bold text-gray-900">Room {room.room_number}</h1>
                    {cleanlinessBadge(room.cleanliness_status)}
                    {occupancyBadge(room.occupancy_status)}
                </div>
                <Link
                    href={`/operations/rooms/${room.id}/edit`}
                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                >
                    Edit
                </Link>
            </div>

            {/* Room Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Room Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Room Number</p>
                        <p className="text-sm font-medium text-gray-900">{room.room_number}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Type</p>
                        <p className="text-sm text-gray-700">{room.room_type.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Floor</p>
                        <p className="text-sm text-gray-700">{room.floor ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Building</p>
                        <p className="text-sm text-gray-700">{room.building ?? '—'}</p>
                    </div>
                </div>

                {room.room_name && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Name</p>
                        <p className="text-sm text-gray-700">{room.room_name}</p>
                    </div>
                )}

                {room.zone && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Zone</p>
                        <p className="text-sm font-mono text-gray-700">{room.zone.zone_code} — {room.zone.zone_name}</p>
                    </div>
                )}

                {room.notes && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Notes</p>
                        <p className="text-sm text-gray-700">{room.notes}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {room.created_at}
                </div>
            </div>

            {/* Status Controls */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                {/* Cleanliness */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-gray-700">Cleanliness</h2>
                            <div className="mt-1">{cleanlinessBadge(room.cleanliness_status)}</div>
                        </div>
                        <button
                            onClick={() => { setShowCleanForm(!showCleanForm); setShowOccupForm(false); }}
                            className="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700"
                        >
                            Change
                        </button>
                    </div>
                    {showCleanForm && (
                        <form onSubmit={submitCleanliness} className="px-6 py-4 space-y-3">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                                <select
                                    value={cleanTarget}
                                    onChange={(e) => setCleanTarget(e.target.value)}
                                    className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    <option value="">— Select status —</option>
                                    <option value="clean">Clean</option>
                                    <option value="dirty">Dirty</option>
                                    <option value="inspected">Inspected</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                                <input
                                    type="text"
                                    value={remarks}
                                    onChange={(e) => setRemarks(e.target.value)}
                                    placeholder="Optional remarks…"
                                    className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={!cleanTarget}
                                className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                            >
                                Update
                            </button>
                        </form>
                    )}
                </div>

                {/* Occupancy */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-gray-700">Occupancy</h2>
                            <div className="mt-1">{occupancyBadge(room.occupancy_status)}</div>
                        </div>
                        <button
                            onClick={() => { setShowOccupForm(!showOccupForm); setShowCleanForm(false); }}
                            className="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700"
                        >
                            Change
                        </button>
                    </div>
                    {showOccupForm && (
                        <form onSubmit={submitOccupancy} className="px-6 py-4 space-y-3">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                                <select
                                    value={occupTarget}
                                    onChange={(e) => setOccupTarget(e.target.value)}
                                    className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    <option value="">— Select status —</option>
                                    <option value="vacant">Vacant</option>
                                    <option value="occupied">Occupied</option>
                                    <option value="blocked">Blocked</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                                <input
                                    type="text"
                                    value={remarks}
                                    onChange={(e) => setRemarks(e.target.value)}
                                    placeholder="Optional remarks…"
                                    className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={!occupTarget}
                                className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                            >
                                Update
                            </button>
                        </form>
                    )}
                </div>
            </div>

            {/* Status History */}
            <div className="bg-white rounded-lg shadow mb-6">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Status History</h2>
                </div>
                {histories.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">No history recorded yet.</div>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {histories.map((h) => (
                            <li key={h.id} className="px-6 py-3 flex items-start gap-3">
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm text-gray-800 capitalize">
                                        {h.action.replace(/_/g, ' ')}
                                        {h.from_status && <span className="text-gray-500"> ({h.from_status} → {h.to_status})</span>}
                                    </p>
                                    {h.remarks && <p className="text-xs text-gray-500 mt-0.5">{h.remarks}</p>}
                                </div>
                                <span className="text-xs text-gray-400 flex-shrink-0">{h.created_at}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {/* Recent Inspections */}
            {inspections.length > 0 && (
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">Inspections</h2>
                        <Link
                            href={`/operations/inspections/create?room_id=${room.id}`}
                            className="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700"
                        >
                            New Inspection
                        </Link>
                    </div>
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Inspected</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {inspections.map((insp) => (
                                <tr key={insp.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 text-gray-700">{insp.inspection_type.label}</td>
                                    <td className="px-6 py-4 text-gray-600">{insp.status.label}</td>
                                    <td className="px-6 py-4 text-gray-500 text-xs">{insp.inspected_at ?? '—'}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={`/operations/inspections/${insp.id}`} className="text-blue-600 hover:text-blue-800 text-xs">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
