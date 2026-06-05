import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { EngineeringChecklist } from '@/Types';

interface Props {
    checklist: EngineeringChecklist;
}

export default function ChecklistsShow({ checklist }: Props) {
    return (
        <AppLayout>
            <div className="max-w-4xl mx-auto">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">{checklist.title}</h1>
                        <p className="text-sm text-gray-500 mt-1">Engineering Checklist</p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href="/operations/engineering/checklists"
                            className="bg-gray-100 text-gray-700 px-4 py-2 rounded shadow hover:bg-gray-200 text-sm"
                        >
                            Back to List
                        </Link>
                        <Link
                            href={`/operations/engineering/checklists/${checklist.id}/edit`}
                            className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm"
                        >
                            Edit
                        </Link>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <div className="px-6 py-5 border-b border-gray-200">
                        <h3 className="text-lg leading-6 font-medium text-gray-900">Checklist Details</h3>
                    </div>
                    <div className="px-6 py-5">
                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-8">
                            <div className="sm:col-span-1">
                                <dt className="text-sm font-medium text-gray-500">Status</dt>
                                <dd className="mt-1 text-sm text-gray-900">
                                    {checklist.is_active ? 'Active' : 'Inactive'}
                                </dd>
                            </div>
                            <div className="sm:col-span-2">
                                <dt className="text-sm font-medium text-gray-500">Description</dt>
                                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                                    {checklist.description || 'No description provided.'}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
