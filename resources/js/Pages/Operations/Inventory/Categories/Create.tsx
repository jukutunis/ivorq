import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';

export default function CategoryCreate() {
    const { data, setData, post, processing, errors } = useForm({
        category_code: '',
        name:          '',
        description:   '',
        is_active:     true as boolean,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/inventory/categories');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inventory/categories" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Categories
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Category</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Category Code <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.category_code}
                                onChange={(e) => setData('category_code', e.target.value.toUpperCase())}
                                placeholder="e.g. HK-AMEN"
                                maxLength={30}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                            />
                            {errors.category_code && <p className="text-red-600 text-xs mt-1">{errors.category_code}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Housekeeping Amenities"
                                maxLength={100}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.name && <p className="text-red-600 text-xs mt-1">{errors.name}</p>}
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
                            className="h-4 w-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500"
                        />
                        <label htmlFor="is_active" className="text-sm text-gray-700">Active</label>
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Creating…' : 'Create Category'}
                    </button>
                    <Link href="/operations/inventory/categories" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
