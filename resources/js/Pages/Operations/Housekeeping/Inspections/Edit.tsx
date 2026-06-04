import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { RoomInspection, EnumOption } from '@/Types';

interface Props {
    inspection:       RoomInspection;
    inspection_types: EnumOption[];
}

export default function InspectionEdit({ inspection, inspection_types }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        inspector_id:     inspection.inspector_id ?? '',
        inspection_type:  String(inspection.inspection_type.value),
        remarks:          inspection.remarks ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/inspections/${inspection.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inspections" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Inspections
                </Link>
                <span className="text-gray-300">/</span>
                <Link
                    href={`/operations/inspections/${inspection.id}`}
                    className="text-sm text-gray-500 hover:text-gray-700"
                >
                    Inspection
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Inspection</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="flex items-center gap-2 p-3 bg-gray-50 rounded border border-gray-200 text-sm text-gray-600">
                        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        To record the inspection result, use Pass / Fail on the inspection detail page.
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Inspection Type</label>
                        <select
                            value={data.inspection_type}
                            onChange={(e) => setData('inspection_type', e.target.value)}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            {inspection_types.map((t) => (
                                <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                            ))}
                        </select>
                        {errors.inspection_type && <p className="text-red-600 text-xs mt-1">{errors.inspection_type}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Inspector ID</label>
                        <input
                            type="text"
                            value={data.inspector_id}
                            onChange={(e) => setData('inspector_id', e.target.value)}
                            placeholder="User ULID"
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.inspector_id && <p className="text-red-600 text-xs mt-1">{errors.inspector_id}</p>}
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
                    <Link
                        href={`/operations/inspections/${inspection.id}`}
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
