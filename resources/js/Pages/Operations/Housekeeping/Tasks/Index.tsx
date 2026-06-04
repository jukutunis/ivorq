import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { CleaningTask, PaginatedData, EnumOption } from '@/Types';

interface Props {
    tasks:      PaginatedData<CleaningTask>;
    task_types: EnumOption[];
    statuses:   EnumOption[];
    filters:    { status?: string; task_type?: string };
}

function statusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        pending:     'bg-gray-100 text-gray-600',
        assigned:    'bg-blue-100 text-blue-700',
        in_progress: 'bg-yellow-100 text-yellow-700',
        completed:   'bg-green-100 text-green-700',
        cancelled:   'bg-red-100 text-red-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function priorityBadge(priority: number) {
    const classes: Record<number, string> = {
        1: 'bg-red-100 text-red-700',
        2: 'bg-orange-100 text-orange-700',
        3: 'bg-yellow-100 text-yellow-700',
        4: 'bg-blue-100 text-blue-700',
        5: 'bg-gray-100 text-gray-700',
    };
    const cls = classes[priority] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            P{priority}
        </span>
    );
}

export default function TaskIndex({ tasks, task_types, statuses, filters }: Props) {
    function applyFilter(field: string, value: string) {
        router.get('/operations/cleaning-tasks', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Cleaning Tasks</h1>
                    <p className="text-sm text-gray-500 mt-1">{tasks.total} task{tasks.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex gap-2">
                    <Link href="/operations/housekeeping" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Dashboard
                    </Link>
                    <Link href="/operations/cleaning-tasks/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                        New Task
                    </Link>
                </div>
            </div>

            {/* Filters */}
            <div className="flex gap-3 mb-4">
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => applyFilter('status', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Statuses</option>
                    {statuses.map((s) => (
                        <option key={String(s.value)} value={String(s.value)}>{s.label}</option>
                    ))}
                </select>
                <select
                    value={filters.task_type ?? ''}
                    onChange={(e) => applyFilter('task_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Types</option>
                    {task_types.map((t) => (
                        <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {tasks.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No tasks found. Create the first cleaning task.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Due</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {tasks.data.map((task) => (
                                <tr key={task.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 font-mono text-xs text-gray-600">{task.task_code}</td>
                                    <td className="px-6 py-4 font-medium text-gray-900">{task.title}</td>
                                    <td className="px-6 py-4 text-gray-600">{task.task_type.label}</td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {task.room ? task.room.room_number : <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4">{statusBadge(task.status)}</td>
                                    <td className="px-6 py-4">{priorityBadge(task.priority)}</td>
                                    <td className="px-6 py-4 text-gray-500 text-xs">{task.due_date ?? '—'}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link
                                            href={`/operations/cleaning-tasks/${task.id}`}
                                            className="text-blue-600 hover:text-blue-800 text-sm"
                                        >
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {tasks.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {tasks.current_page} of {tasks.last_page}</span>
                        <div className="flex gap-1">
                            {tasks.links.map((link, i) => (
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${link.active ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span key={i} className="px-3 py-1 rounded border border-gray-200 text-xs text-gray-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
