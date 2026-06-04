import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { CleaningTask, RoomInspection, EnumOption } from '@/Types';

interface Stats {
    total_rooms:         number;
    dirty_rooms:         number;
    clean_rooms:         number;
    pending_tasks:       number;
    in_progress_tasks:   number;
    pending_inspections: number;
}

interface Props {
    stats:               Stats;
    todays_tasks:        { data: CleaningTask[] };
    failed_inspections:  { data: RoomInspection[] };
}

function taskStatusBadge(status: EnumOption) {
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

function severityBadge(severity: EnumOption | null) {
    if (!severity) return <span className="text-gray-400 text-xs">—</span>;
    const classes: Record<string, string> = {
        minor:    'bg-yellow-100 text-yellow-700',
        major:    'bg-orange-100 text-orange-700',
        critical: 'bg-red-100 text-red-700',
    };
    const cls = classes[String(severity.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {severity.label}
        </span>
    );
}

function StatCard({
    label,
    value,
    href,
    colorClass,
}: {
    label: string;
    value: number;
    href: string;
    colorClass: string;
}) {
    return (
        <Link href={href} className="bg-white rounded-lg shadow p-6 hover:shadow-md transition-shadow">
            <p className="text-sm text-gray-500 mb-1">{label}</p>
            <p className={`text-3xl font-bold ${colorClass}`}>{value}</p>
        </Link>
    );
}

export default function HousekeepingDashboard({ stats, todays_tasks, failed_inspections }: Props) {
    const tasks       = todays_tasks?.data ?? [];
    const failedInsp  = failed_inspections?.data ?? [];

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Housekeeping</h1>
                    <p className="text-sm text-gray-500 mt-1">Operations overview</p>
                </div>
                <div className="flex gap-2">
                    <Link href="/operations/rooms" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Rooms
                    </Link>
                    <Link href="/operations/cleaning-tasks" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Tasks
                    </Link>
                    <Link href="/operations/inspections" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Inspections
                    </Link>
                    <Link href="/operations/checklists" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Checklists
                    </Link>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                <StatCard
                    label="Total Rooms"
                    value={stats.total_rooms}
                    href="/operations/rooms"
                    colorClass="text-gray-900"
                />
                <StatCard
                    label="Dirty Rooms"
                    value={stats.dirty_rooms}
                    href="/operations/rooms"
                    colorClass="text-red-600"
                />
                <StatCard
                    label="Clean Rooms"
                    value={stats.clean_rooms}
                    href="/operations/rooms"
                    colorClass="text-green-600"
                />
                <StatCard
                    label="Pending Tasks"
                    value={stats.pending_tasks}
                    href="/operations/cleaning-tasks?status=pending"
                    colorClass="text-gray-700"
                />
                <StatCard
                    label="In Progress"
                    value={stats.in_progress_tasks}
                    href="/operations/cleaning-tasks?status=in_progress"
                    colorClass="text-yellow-600"
                />
                <StatCard
                    label="Pending Inspections"
                    value={stats.pending_inspections}
                    href="/operations/inspections"
                    colorClass="text-blue-600"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Today's Tasks */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">Today's Tasks</h2>
                        <Link
                            href="/operations/cleaning-tasks/create"
                            className="text-blue-600 hover:text-blue-800 text-xs"
                        >
                            New task
                        </Link>
                    </div>

                    {tasks.length === 0 ? (
                        <div className="px-6 py-8 text-center text-gray-400 text-sm">
                            No tasks due today.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Task</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {tasks.map((task) => (
                                    <tr key={task.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/operations/cleaning-tasks/${task.id}`}
                                                className="font-medium text-gray-900 hover:text-blue-600 text-xs block truncate max-w-[180px]"
                                            >
                                                {task.title}
                                            </Link>
                                            <span className="text-xs text-gray-400 font-mono">{task.task_code}</span>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-600">
                                            {task.room?.room_number ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">{taskStatusBadge(task.status)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="px-6 py-3 border-t border-gray-100">
                        <Link href="/operations/cleaning-tasks" className="text-xs text-blue-600 hover:text-blue-800">
                            View all tasks →
                        </Link>
                    </div>
                </div>

                {/* Failed Inspections */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">Recent Failed Inspections</h2>
                        <Link
                            href="/operations/inspections/create"
                            className="text-blue-600 hover:text-blue-800 text-xs"
                        >
                            New inspection
                        </Link>
                    </div>

                    {failedInsp.length === 0 ? (
                        <div className="px-6 py-8 text-center text-gray-400 text-sm">
                            No failed inspections.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Inspector</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {failedInsp.map((insp) => (
                                    <tr key={insp.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/operations/inspections/${insp.id}`}
                                                className="font-medium text-gray-900 hover:text-blue-600 text-xs"
                                            >
                                                {insp.room?.room_number ?? '—'}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-600">
                                            {insp.inspector?.name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">{severityBadge(insp.inspection_severity)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="px-6 py-3 border-t border-gray-100">
                        <Link href="/operations/inspections" className="text-xs text-blue-600 hover:text-blue-800">
                            View all inspections →
                        </Link>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
