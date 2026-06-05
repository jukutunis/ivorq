import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { AssetRequest, PaginatedData } from '@/Types';

interface Props {
    asset_requests?: PaginatedData<AssetRequest>;
}

export default function AssetRequestsIndex({ asset_requests }: Props) {
    const data = asset_requests?.data ?? [];

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Asset Requests</h1>
                    <p className="text-sm text-gray-500 mt-1">Manage requests for new assets</p>
                </div>
                <div>
                    <Link
                        href="/operations/engineering/asset-requests/create"
                        className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm"
                    >
                        Create Request
                    </Link>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request Number</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {data.length === 0 ? (
                            <tr>
                                <td colSpan={4} className="px-6 py-8 text-center text-gray-500 text-sm">
                                    No asset requests found.
                                </td>
                            </tr>
                        ) : (
                            data.map((request) => (
                                <tr key={request.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                        <Link href={`/operations/engineering/asset-requests/${request.id}`}>
                                            {request.request_number}
                                        </Link>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 truncate max-w-xs">
                                        {request.title}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {typeof request.status === 'object' ? request.status.label : request.status}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link href={`/operations/engineering/asset-requests/${request.id}/edit`} className="text-blue-600 hover:text-blue-900 mr-3">
                                            Edit
                                        </Link>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
