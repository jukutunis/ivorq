import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { PreventiveMaintenance, PaginatedData } from '@/Types';

interface Props {
    preventive_maintenances?: PaginatedData<PreventiveMaintenance>;
}

export default function PreventiveMaintenancesIndex({ preventive_maintenances }: Props) {
    const data = preventive_maintenances?.data ?? [];

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Preventive Maintenance</h1>
                    <p className="text-sm text-gray-500 mt-1">Manage PM schedules and tasks</p>
                </div>
                <div>
                    <Link
                        href="/operations/engineering/preventive-maintenances/create"
                        className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm"
                    >
                        Create PM
                    </Link>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Frequency</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {data.length === 0 ? (
                            <tr>
                                <td colSpan={4} className="px-6 py-8 text-center text-gray-500 text-sm">
                                    No preventive maintenance plans found.
                                </td>
                            </tr>
                        ) : (
                            data.map((pm) => (
                                <tr key={pm.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                        <Link href={`/operations/engineering/preventive-maintenances/${pm.id}`}>
                                            {pm.title}
                                        </Link>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {typeof pm.frequency === 'object' ? pm.frequency.label : pm.frequency}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {typeof pm.status === 'object' ? pm.status.label : pm.status}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link href={`/operations/engineering/preventive-maintenances/${pm.id}/edit`} className="text-blue-600 hover:text-blue-900 mr-3">
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
