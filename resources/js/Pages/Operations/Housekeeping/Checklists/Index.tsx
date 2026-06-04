import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { CleaningChecklist, PaginatedData, EnumOption } from '@/Types';

interface Props {
    checklists: PaginatedData<CleaningChecklist>;
    task_types: EnumOption[];
}

function taskTypeBadge(taskType: EnumOption | null) {
    if (!taskType) return <span className="text-gray-400 text-xs">General</span>;
    const classes: Record<string, string> = {
        checkout_cleaning: 'bg-blue-100 text-blue-700',
        stayover_cleaning: 'bg-teal-100 text-teal-700',
        turndown:          'bg-purple-100 text-purple-700',
        deep_cleaning:     'bg-orange-100 text-orange-700',
        public_area:       'bg-slate-100 text-slate-700',
        spot_check:        'bg-yellow-100 text-yellow-700',
        custom:            'bg-gray-100 text-gray-700',
    };
    const cls = classes[String(taskType.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {taskType.label}
        </span>
    );
}

function activeBadge(isActive: boolean) {
    return isActive ? (
        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">
            Active
        </span>
    ) : (
        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">
            Inactive
        </span>
    );
}

export default function ChecklistIndex({ checklists, task_types }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Cleaning Checklists</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        {checklists.total} checklist{checklists.total !== 1 ? 's' : ''} total
                    </p>
                </div>
                <Link
                    href="/operations/checklists/create"
                    className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700"
                >
                    New Checklist
                </Link>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {checklists.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No checklists found. Create the first cleaning checklist.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Task Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {checklists.data.map((cl) => (
                                <tr key={cl.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4">
                                        <p className="font-medium text-gray-900">{cl.name}</p>
                                        {cl.description && (
                                            <p className="text-xs text-gray-500 mt-0.5 truncate max-w-xs">{cl.description}</p>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">{taskTypeBadge(cl.task_type)}</td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {cl.items_count ?? '—'}
                                    </td>
                                    <td className="px-6 py-4">{activeBadge(cl.is_active)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <div className="flex justify-end gap-3">
                                            <Link
                                                href={`/operations/checklists/${cl.id}`}
                                                className="text-blue-600 hover:text-blue-800 text-sm"
                                            >
                                                View
                                            </Link>
                                            <Link
                                                href={`/operations/checklists/${cl.id}/edit`}
                                                className="text-gray-500 hover:text-gray-700 text-sm"
                                            >
                                                Edit
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {checklists.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {checklists.current_page} of {checklists.last_page}</span>
                        <div className="flex gap-1">
                            {checklists.links.map((link, i) => (
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${
                                            link.active
                                                ? 'bg-blue-600 text-white border-blue-600'
                                                : 'border-gray-300 hover:bg-gray-50'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span
                                        key={i}
                                        className="px-3 py-1 rounded border border-gray-200 text-xs text-gray-400"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
