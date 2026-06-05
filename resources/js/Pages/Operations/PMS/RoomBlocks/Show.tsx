import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { RoomBlock, EnumOption } from '@/Types';
import { useState } from 'react';
import axios from 'axios';

interface Props {
    room_block: RoomBlock;
}

function blockStatusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        active:   'bg-yellow-100 text-yellow-700',
        released: 'bg-green-100 text-green-700',
        expired:  'bg-gray-100 text-gray-600',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

export default function RoomBlockShow({ room_block }: Props) {
    const [loading, setLoading] = useState(false);
    const statusVal = String(room_block.status.value);

    function doRelease() {
        if (!confirm('Release this room block?')) return;
        if (loading) return;
        setLoading(true);
        axios
            .post(`/operations/pms/room-blocks/${room_block.id}/release`)
            .then(() => router.reload())
            .finally(() => setLoading(false));
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/room-blocks" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Room Blocks
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">
                        Room {room_block.room?.room_number ?? '—'}
                    </h1>
                    {blockStatusBadge(room_block.status)}
                </div>
                <Link
                    href={`/operations/pms/room-blocks/${room_block.id}/edit`}
                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                >
                    Edit
                </Link>
            </div>

            {/* Block Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Block Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Room</p>
                        <p className="text-sm text-gray-700">
                            {room_block.room ? (
                                <span>{room_block.room.room_number} — {room_block.room.room_type.label}</span>
                            ) : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Block Type</p>
                        <p className="text-sm text-gray-700">{room_block.block_type.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Reason</p>
                        <p className="text-sm text-gray-700">{room_block.reason?.label ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Start</p>
                        <p className="text-sm text-gray-700">{room_block.start_at}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">End</p>
                        <p className="text-sm text-gray-700">{room_block.end_at ?? '—'}</p>
                    </div>
                    {room_block.released_at && (
                        <>
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Released At</p>
                                <p className="text-sm text-gray-700">{room_block.released_at}</p>
                            </div>
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Released By</p>
                                <p className="text-sm text-gray-700">{room_block.released_by_user?.name ?? '—'}</p>
                            </div>
                        </>
                    )}
                </div>

                {room_block.notes && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Notes</p>
                        <p className="text-sm text-gray-700">{room_block.notes}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {room_block.created_at}
                </div>
            </div>

            {/* Actions */}
            {statusVal === 'active' && (
                <div className="bg-white rounded-lg shadow p-6">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Actions</h2>
                    <button
                        onClick={doRelease}
                        disabled={loading}
                        className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700 disabled:opacity-60"
                    >
                        {loading ? '…' : 'Release Block'}
                    </button>
                </div>
            )}
        </AppLayout>
    );
}
