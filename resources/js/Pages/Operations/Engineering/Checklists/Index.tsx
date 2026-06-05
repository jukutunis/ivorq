import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { EngineeringChecklist, PaginatedData } from '@/Types';

interface Props {
    checklists?: PaginatedData<EngineeringChecklist>;
}

export default function ChecklistsIndex({ checklists }: Props) {
    const data = checklists?.data ?? [];

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Engineering Checklists</h1>
                    <p className="text-sm text-gray-500 mt-1">Manage checklists used in tasks</p>
                </div>
                <div>
                    <Link
                        href="/operations/engineering/checklists/create"
                        className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm"
                    >
                        Create Checklist
                    </Link>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {data.length === 0 ? (
                            <tr>
                                <td colSpan={3} className="px-6 py-8 text-center text-gray-500 text-sm">
                                    No checklists found.
                                </td>
                            </tr>
                        ) : (
                            data.map((checklist) => (
                                <tr key={checklist.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                        <Link href={`/operations/engineering/checklists/${checklist.id}`}>
                                            {checklist.title}
                                        </Link>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {checklist.is_active ? 'Active' : 'Inactive'}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link href={`/operations/engineering/checklists/${checklist.id}/edit`} className="text-blue-600 hover:text-blue-900 mr-3">
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
