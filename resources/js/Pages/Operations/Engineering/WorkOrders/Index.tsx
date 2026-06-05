import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { WorkOrder, PaginatedData } from '@/Types';

interface Props {
    work_orders?: PaginatedData<WorkOrder>;
}

export default function WorkOrdersIndex({ work_orders }: Props) {
    const data = work_orders?.data ?? [];

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Work Orders</h1>
                    <p className="text-sm text-gray-500 mt-1">Manage all engineering work orders</p>
                </div>
                <div>
                    <Link
                        href="/operations/engineering/work-orders/create"
                        className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm"
                    >
                        Create Work Order
                    </Link>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {data.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-6 py-8 text-center text-gray-500 text-sm">
                                    No work orders found.
                                </td>
                            </tr>
                        ) : (
                            data.map((wo) => (
                                <tr key={wo.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                        <Link href={`/operations/engineering/work-orders/${wo.id}`}>
                                            {wo.work_order_number}
                                        </Link>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 truncate max-w-xs">
                                        {wo.title}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {typeof wo.work_order_type === 'object' ? wo.work_order_type.label : wo.work_order_type}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {typeof wo.status === 'object' ? wo.status.label : wo.status}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link href={`/operations/engineering/work-orders/${wo.id}/edit`} className="text-blue-600 hover:text-blue-900 mr-3">
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
