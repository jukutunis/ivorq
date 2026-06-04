import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface Props {
    inspection_types: EnumOption[];
}

export default function InspectionCreate({ inspection_types }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        room_id:          '',
        cleaning_task_id: '',
        inspector_id:     '',
        inspection_type:  '',
        remarks:          '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/inspections');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inspections" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Inspections
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Inspection</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="grid grid-cols-2 gap-4">
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

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Inspection Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.inspection_type}
                                onChange={(e) => setData('inspection_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select type —</option>
                                {inspection_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.inspection_type && <p className="text-red-600 text-xs mt-1">{errors.inspection_type}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Inspector ID <span className="text-gray-400 font-normal">(optional)</span>
                            </label>
                            <input
                                type="text"
                                value={data.inspector_id}
                                onChange={(e) => setData('inspector_id', e.target.value)}
                                placeholder="User ULID (defaults to you)"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.inspector_id && <p className="text-red-600 text-xs mt-1">{errors.inspector_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Cleaning Task ID <span className="text-gray-400 font-normal">(optional)</span>
                            </label>
                            <input
                                type="text"
                                value={data.cleaning_task_id}
                                onChange={(e) => setData('cleaning_task_id', e.target.value)}
                                placeholder="Task ULID"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.cleaning_task_id && <p className="text-red-600 text-xs mt-1">{errors.cleaning_task_id}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                        <textarea
                            value={data.remarks}
                            onChange={(e) => setData('remarks', e.target.value)}
                            rows={3}
                            placeholder="Optional initial remarks…"
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
                        {processing ? 'Creating…' : 'Create Inspection'}
                    </button>
                    <Link
                        href="/operations/inspections"
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
