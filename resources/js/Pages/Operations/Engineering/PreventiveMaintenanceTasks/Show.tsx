import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { PreventiveMaintenanceTask } from '@/Types';

interface Props {
    task: PreventiveMaintenanceTask;
}

export default function PreventiveMaintenanceTasksShow({ task }: Props) {
    return (
        <AppLayout>
            <div className="max-w-4xl mx-auto">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">{task.preventive_maintenance?.title}</h1>
                        <p className="text-sm text-gray-500 mt-1">Preventive Maintenance Task</p>
                    </div>
                    <div>
                        <Link
                            href={`/operations/engineering/preventive-maintenances/${task.preventive_maintenance_id}`}
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded shadow hover:bg-gray-200 text-sm"
                        >
                            Back to PM Plan
                        </Link>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <div className="px-6 py-5 border-b border-gray-200">
                        <h3 className="text-lg leading-6 font-medium text-gray-900">Task Details</h3>
                    </div>
                    <div className="px-6 py-5">
                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-8">
                            <div className="sm:col-span-1">
                                <dt className="text-sm font-medium text-gray-500">Status</dt>
                                <dd className="mt-1 text-sm text-gray-900">
                                    {typeof task.status === 'object' ? task.status.label : task.status}
                                </dd>
                            </div>
                            <div className="sm:col-span-1">
                                <dt className="text-sm font-medium text-gray-500">Due Date</dt>
                                <dd className="mt-1 text-sm text-gray-900">
                                    {task.scheduled_date ? new Date(task.scheduled_date).toLocaleDateString() : '—'}
                                </dd>
                            </div>
                            <div className="sm:col-span-1">
                                <dt className="text-sm font-medium text-gray-500">Completed At</dt>
                                <dd className="mt-1 text-sm text-gray-900">
                                    {task.completed_at ? new Date(task.completed_at).toLocaleString() : '—'}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
