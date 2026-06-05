import AppLayout from '@/Layouts/AppLayout';
import { useForm, Link } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function WorkOrdersCreate() {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        work_order_type: 'repair',
        priority: 3,
        status: 'pending',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/operations/engineering/work-orders');
    };

    return (
        <AppLayout>
            <div className="max-w-2xl mx-auto">
                <div className="flex items-center justify-between mb-6">
                    <h1 className="text-2xl font-bold text-gray-900">Create Work Order</h1>
                    <Link href="/operations/engineering/work-orders" className="text-sm text-gray-600 hover:text-gray-900">
                        Cancel
                    </Link>
                </div>

                <div className="bg-white rounded-lg shadow p-6">
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Title</label>
                            <input
                                type="text"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            />
                            {errors.title && <div className="text-red-600 text-xs mt-1">{errors.title}</div>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">Description</label>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={4}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            />
                            {errors.description && <div className="text-red-600 text-xs mt-1">{errors.description}</div>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">Type</label>
                            <select
                                value={data.work_order_type}
                                onChange={(e) => setData('work_order_type', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            >
                                <option value="repair">Repair</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="inspection">Inspection</option>
                            </select>
                            {errors.work_order_type && <div className="text-red-600 text-xs mt-1">{errors.work_order_type}</div>}
                        </div>

                        <div className="pt-4 flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm disabled:opacity-50"
                            >
                                {processing ? 'Saving...' : 'Create'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
