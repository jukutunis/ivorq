import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface Props {
    plan_types: EnumOption[];
}

export default function RatePlanCreate({ plan_types }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        rate_code:   '',
        rate_name:   '',
        plan_type:   '',
        base_rate:   '',
        currency:    'MYR',
        is_active:   true as boolean,
        description: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/pms/rate-plans');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/rate-plans" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Rate Plans
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Rate Plan</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Rate Code <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.rate_code}
                                onChange={(e) => setData('rate_code', e.target.value)}
                                placeholder="e.g. RACK-STD"
                                maxLength={20}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.rate_code && <p className="text-red-600 text-xs mt-1">{errors.rate_code}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Plan Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.plan_type}
                                onChange={(e) => setData('plan_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select type —</option>
                                {plan_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.plan_type && <p className="text-red-600 text-xs mt-1">{errors.plan_type}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Rate Name <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.rate_name}
                            onChange={(e) => setData('rate_name', e.target.value)}
                            placeholder="e.g. Standard Rack Rate"
                            maxLength={255}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.rate_name && <p className="text-red-600 text-xs mt-1">{errors.rate_name}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Base Rate <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                value={data.base_rate}
                                onChange={(e) => setData('base_rate', e.target.value)}
                                min={0}
                                step={0.01}
                                placeholder="e.g. 250.00"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.base_rate && <p className="text-red-600 text-xs mt-1">{errors.base_rate}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                            <input
                                type="text"
                                value={data.currency}
                                onChange={(e) => setData('currency', e.target.value)}
                                maxLength={3}
                                placeholder="MYR"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.currency && <p className="text-red-600 text-xs mt-1">{errors.currency}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={3}
                            placeholder="Optional description…"
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.description && <p className="text-red-600 text-xs mt-1">{errors.description}</p>}
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            id="is_active"
                            type="checkbox"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                        />
                        <label htmlFor="is_active" className="text-sm font-medium text-gray-700">Active</label>
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Creating…' : 'Create Rate Plan'}
                    </button>
                    <Link href="/operations/pms/rate-plans" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
