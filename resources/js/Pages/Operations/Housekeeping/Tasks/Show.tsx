import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm, router } from '@inertiajs/react';
import { CleaningTask, TaskAssignment, EnumOption } from '@/Types';
import { useState } from 'react';
import axios from 'axios';

interface Props {
    task: CleaningTask;
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
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
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
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            P{priority}
        </span>
    );
}

function assignmentStatusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        active:    'bg-green-100 text-green-700',
        completed: 'bg-blue-100 text-blue-700',
        cancelled: 'bg-red-100 text-red-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function AssignForm({ task }: { task: CleaningTask }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id:       '',
        department_id: '',
        notes:         '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(`/operations/cleaning-tasks/${task.id}/assign`, { onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        User ID <span className="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        value={data.user_id}
                        onChange={(e) => setData('user_id', e.target.value)}
                        placeholder="User ULID"
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.user_id && <p className="text-red-600 text-xs mt-1">{errors.user_id}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        Department ID <span className="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        value={data.department_id}
                        onChange={(e) => setData('department_id', e.target.value)}
                        placeholder="Department ULID"
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.department_id && <p className="text-red-600 text-xs mt-1">{errors.department_id}</p>}
                </div>
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <input
                    type="text"
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    placeholder="Optional notes…"
                    className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
            </div>
            <button
                type="submit"
                disabled={processing}
                className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
            >
                {processing ? 'Assigning…' : 'Assign'}
            </button>
        </form>
    );
}

export default function TaskShow({ task }: Props) {
    const [showAssignForm, setShowAssignForm] = useState(false);
    const [statusLoading, setStatusLoading]   = useState(false);
    const assignments = task.assignments ?? [];
    const statusVal   = String(task.status.value);

    function changeStatus(status: string, remarks?: string) {
        if (statusLoading) return;
        setStatusLoading(true);
        axios.post(`/operations/cleaning-tasks/${task.id}/status`, { status, remarks })
            .then(() => router.reload())
            .finally(() => setStatusLoading(false));
    }

    function completeAssignment(assignmentId: string) {
        if (!confirm('Mark this assignment as completed?')) return;
        router.post(
            `/operations/cleaning-tasks/${task.id}/assignments/${assignmentId}/complete`,
            {},
            { preserveScroll: true }
        );
    }

    function cancelAssignment(assignmentId: string) {
        if (!confirm('Cancel this assignment?')) return;
        router.post(
            `/operations/cleaning-tasks/${task.id}/assignments/${assignmentId}/cancel`,
            {},
            { preserveScroll: true }
        );
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/cleaning-tasks" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Tasks
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{task.task_code}</h1>
                    {statusBadge(task.status)}
                    {priorityBadge(task.priority)}
                </div>
                <Link
                    href={`/operations/cleaning-tasks/${task.id}/edit`}
                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                >
                    Edit
                </Link>
            </div>

            {/* Task Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Task Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Type</p>
                        <p className="text-sm text-gray-700">{task.task_type.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Room</p>
                        <p className="text-sm text-gray-700">
                            {task.room ? (
                                <Link href={`/operations/rooms/${task.room.id}`} className="text-blue-600 hover:text-blue-800">
                                    {task.room.room_number}
                                </Link>
                            ) : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Due Date</p>
                        <p className="text-sm text-gray-700">{task.due_date ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Est. Duration</p>
                        <p className="text-sm text-gray-700">
                            {task.estimated_duration_minutes ? `${task.estimated_duration_minutes} min` : '—'}
                        </p>
                    </div>
                </div>

                {task.description && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Description</p>
                        <p className="text-sm text-gray-700">{task.description}</p>
                    </div>
                )}

                {(task.started_at || task.completed_at) && (
                    <div className="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 gap-4">
                        {task.started_at && (
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Started</p>
                                <p className="text-sm text-gray-700">{task.started_at}</p>
                            </div>
                        )}
                        {task.completed_at && (
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Completed</p>
                                <p className="text-sm text-gray-700">{task.completed_at}</p>
                            </div>
                        )}
                        {task.actual_duration_minutes && (
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Actual Duration</p>
                                <p className="text-sm text-gray-700">{task.actual_duration_minutes} min</p>
                            </div>
                        )}
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {task.created_at}
                </div>
            </div>

            {/* Status Actions */}
            {!['completed', 'cancelled'].includes(statusVal) && (
                <div className="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Actions</h2>
                    <div className="flex flex-wrap gap-3">
                        {statusVal === 'pending' && (
                            <button
                                onClick={() => setShowAssignForm(!showAssignForm)}
                                className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700"
                            >
                                Assign
                            </button>
                        )}
                        {statusVal === 'assigned' && (
                            <button
                                onClick={() => changeStatus('in_progress')}
                                disabled={statusLoading}
                                className="bg-yellow-500 text-white px-4 py-2 rounded text-sm hover:bg-yellow-600 disabled:opacity-60"
                            >
                                {statusLoading ? '…' : 'Start Task'}
                            </button>
                        )}
                        {statusVal === 'in_progress' && (
                            <button
                                onClick={() => changeStatus('completed')}
                                disabled={statusLoading}
                                className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700 disabled:opacity-60"
                            >
                                {statusLoading ? '…' : 'Mark Complete'}
                            </button>
                        )}
                        {['pending', 'assigned', 'in_progress'].includes(statusVal) && (
                            <button
                                onClick={() => {
                                    if (confirm('Cancel this task?')) changeStatus('cancelled');
                                }}
                                disabled={statusLoading}
                                className="bg-red-100 text-red-700 px-4 py-2 rounded text-sm hover:bg-red-200 disabled:opacity-60"
                            >
                                Cancel Task
                            </button>
                        )}
                    </div>

                    {showAssignForm && (
                        <div className="mt-4 pt-4 border-t border-gray-100">
                            <p className="text-sm font-medium text-gray-700 mb-3">Assign Task</p>
                            <AssignForm task={task} />
                        </div>
                    )}
                </div>
            )}

            {/* Assignments */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-700">
                        Assignments
                        {assignments.length > 0 && (
                            <span className="ml-2 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs">
                                {assignments.length}
                            </span>
                        )}
                    </h2>
                </div>

                {assignments.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">No assignments yet.</div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned To</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {assignments.map((a) => (
                                <tr key={a.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 text-gray-900">
                                        {a.user?.name ?? <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {a.department?.name ?? <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4">{assignmentStatusBadge(a.status)}</td>
                                    <td className="px-6 py-4 text-gray-500 text-xs">{a.assigned_at}</td>
                                    <td className="px-6 py-4 text-right">
                                        {String(a.status.value) === 'active' && (
                                            <div className="flex justify-end gap-2">
                                                <button
                                                    onClick={() => completeAssignment(a.id)}
                                                    className="text-green-600 hover:text-green-800 text-xs"
                                                >
                                                    Complete
                                                </button>
                                                <button
                                                    onClick={() => cancelAssignment(a.id)}
                                                    className="text-red-600 hover:text-red-800 text-xs"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}
