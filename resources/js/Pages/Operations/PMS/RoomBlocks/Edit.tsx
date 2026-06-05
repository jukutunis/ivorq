import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { RoomBlock, EnumOption } from '@/Types';

interface Props {
    room_block:  RoomBlock;
    block_types: EnumOption[];
    reasons:     EnumOption[];
}

export default function RoomBlockEdit({ room_block, block_types, reasons }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        room_id:    room_block.room_id,
        block_type: String(room_block.block_type.value),
        reason:     room_block.reason ? String(room_block.reason.value) : '',
        notes:      room_block.notes ?? '',
        start_at:   room_block.start_at,
        end_at:     room_block.end_at ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/pms/room-blocks/${room_block.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/room-blocks" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Room Blocks
                </Link>
                <span className="text-gray-300">/</span>
                <Link href={`/operations/pms/room-blocks/${room_block.id}`} className="text-sm text-gray-500 hover:text-gray-700">
                    Block
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Room Block</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="flex items-center gap-2 p-3 bg-gray-50 rounded border border-gray-200 text-sm text-gray-600">
                        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        To release this block, use the Release action on the block detail page.
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Room ID <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.room_id}
                            onChange={(e) => setData('room_id', e.target.value)}
                            placeholder="Room ULID"
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.room_id && <p className="text-red-600 text-xs mt-1">{errors.room_id}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Block Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.block_type}
                                onChange={(e) => setData('block_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {block_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.block_type && <p className="text-red-600 text-xs mt-1">{errors.block_type}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Reason</label>
                            <select
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— None —</option>
                                {reasons.map((r) => (
                                    <option key={String(r.value)} value={String(r.value)}>{r.label}</option>
                                ))}
                            </select>
                            {errors.reason && <p className="text-red-600 text-xs mt-1">{errors.reason}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Start Date <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="date"
                                value={data.start_at}
                                onChange={(e) => setData('start_at', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.start_at && <p className="text-red-600 text-xs mt-1">{errors.start_at}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                            <input
                                type="date"
                                value={data.end_at}
                                onChange={(e) => setData('end_at', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.end_at && <p className="text-red-600 text-xs mt-1">{errors.end_at}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={3}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.notes && <p className="text-red-600 text-xs mt-1">{errors.notes}</p>}
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
                    <Link href={`/operations/pms/room-blocks/${room_block.id}`} className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                    <Link
                        href={`/operations/pms/room-blocks/${room_block.id}`}
                        method="delete"
                        as="button"
                        className="ml-auto text-red-600 hover:text-red-800 text-sm px-3 py-2"
                        onBefore={() => confirm('Delete this room block?')}
                    >
                        Delete Block
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
