import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface Props {
    block_types: EnumOption[];
    reasons:     EnumOption[];
}

export default function RoomBlockCreate({ block_types, reasons }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        room_id:    '',
        block_type: '',
        reason:     '',
        notes:      '',
        start_at:   '',
        end_at:     '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/pms/room-blocks');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/room-blocks" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Room Blocks
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Room Block</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

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
                                <option value="">— Select type —</option>
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
                            placeholder="Optional notes…"
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
                        {processing ? 'Creating…' : 'Create Block'}
                    </button>
                    <Link href="/operations/pms/room-blocks" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
