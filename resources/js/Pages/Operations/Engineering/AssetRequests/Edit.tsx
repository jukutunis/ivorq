import AppLayout from '@/Layouts/AppLayout';
import { useForm, Link } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { AssetRequest } from '@/Types';

interface Props {
    asset_request: AssetRequest;
}

export default function AssetRequestsEdit({ asset_request }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        title: asset_request.title || '',
        description: asset_request.description || '',
        status: typeof asset_request.status === 'object' ? asset_request.status.value : asset_request.status,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/operations/engineering/asset-requests/${asset_request.id}`);
    };

    return (
        <AppLayout>
            <div className="max-w-2xl mx-auto">
                <div className="flex items-center justify-between mb-6">
                    <h1 className="text-2xl font-bold text-gray-900">Edit Asset Request</h1>
                    <Link href={`/operations/engineering/asset-requests/${asset_request.id}`} className="text-sm text-gray-600 hover:text-gray-900">
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
                            <label className="block text-sm font-medium text-gray-700">Status</label>
                            <select
                                value={data.status as string}
                                onChange={(e) => setData('status', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            >
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="completed">Completed</option>
                            </select>
                            {errors.status && <div className="text-red-600 text-xs mt-1">{errors.status}</div>}
                        </div>

                        <div className="pt-4 flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm disabled:opacity-50"
                            >
                                {processing ? 'Saving...' : 'Save Changes'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
