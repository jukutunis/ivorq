import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';

export default function UnitCreate() {
    const { data, setData, post, processing, errors } = useForm({
        unit_code:    '',
        name:         '',
        abbreviation: '',
        is_active:    true as boolean,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/inventory/units');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/units" className="text-sm text-gray-500 hover:text-gray-700">← Units</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Unit of Measure</h1>

            <form onSubmit={submit} className="max-w-lg">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Unit Code <span className="text-red-500">*</span></label>
                            <input type="text" value={data.unit_code}
                                onChange={(e) => setData('unit_code', e.target.value.toUpperCase())}
                                placeholder="e.g. PCS" maxLength={20}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono" />
                            {errors.unit_code && <p className="text-red-600 text-xs mt-1">{errors.unit_code}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Abbreviation <span className="text-red-500">*</span></label>
                            <input type="text" value={data.abbreviation}
                                onChange={(e) => setData('abbreviation', e.target.value)}
                                placeholder="e.g. pcs" maxLength={10}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono" />
                            {errors.abbreviation && <p className="text-red-600 text-xs mt-1">{errors.abbreviation}</p>}
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Name <span className="text-red-500">*</span></label>
                        <input type="text" value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Piece" maxLength={50}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                        {errors.name && <p className="text-red-600 text-xs mt-1">{errors.name}</p>}
                    </div>
                    <div className="flex items-center gap-2">
                        <input id="is_active" type="checkbox" checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="h-4 w-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500" />
                        <label htmlFor="is_active" className="text-sm text-gray-700">Active</label>
                    </div>
                </div>
                <div className="flex items-center gap-3 mt-4">
                    <button type="submit" disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60">
                        {processing ? 'Creating…' : 'Create Unit'}
                    </button>
                    <Link href="/operations/inventory/units" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">Cancel</Link>
                </div>
            </form>
        </AppLayout>
    );
}
