import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface Props {
    task_types: EnumOption[];
}

export default function TaskCreate({ task_types }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        task_code:                  '',
        title:                      '',
        description:                '',
        task_type:                  '',
        priority:                   '3',
        estimated_duration_minutes: '',
        room_id:                    '',
        zone_id:                    '',
        due_date:                   '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/cleaning-tasks');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/cleaning-tasks" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Tasks
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">New Cleaning Task</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Task Code <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.task_code}
                                onChange={(e) => setData('task_code', e.target.value)}
                                placeholder="e.g. TSK-001"
                                maxLength={20}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.task_code && <p className="text-red-600 text-xs mt-1">{errors.task_code}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Task Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.task_type}
                                onChange={(e) => setData('task_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select type —</option>
                                {task_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.task_type && <p className="text-red-600 text-xs mt-1">{errors.task_type}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Title <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="e.g. Checkout Clean Room 101"
                            maxLength={255}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.title && <p className="text-red-600 text-xs mt-1">{errors.title}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={3}
                            placeholder="Optional description…"
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.description && <p className="text-red-600 text-xs mt-1">{errors.description}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Priority (1=high, 5=low)</label>
                            <select
                                value={data.priority}
                                onChange={(e) => setData('priority', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {[1,2,3,4,5].map((p) => (
                                    <option key={p} value={String(p)}>Priority {p}</option>
                                ))}
                            </select>
                            {errors.priority && <p className="text-red-600 text-xs mt-1">{errors.priority}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Est. Duration (min)</label>
                            <input
                                type="number"
                                value={data.estimated_duration_minutes}
                                onChange={(e) => setData('estimated_duration_minutes', e.target.value)}
                                min={1}
                                placeholder="e.g. 30"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.estimated_duration_minutes && <p className="text-red-600 text-xs mt-1">{errors.estimated_duration_minutes}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Room ID</label>
                            <input
                                type="text"
                                value={data.room_id}
                                onChange={(e) => setData('room_id', e.target.value)}
                                placeholder="Room ULID (optional)"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.room_id && <p className="text-red-600 text-xs mt-1">{errors.room_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                            <input
                                type="datetime-local"
                                value={data.due_date}
                                onChange={(e) => setData('due_date', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.due_date && <p className="text-red-600 text-xs mt-1">{errors.due_date}</p>}
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Creating…' : 'Create Task'}
                    </button>
                    <Link href="/operations/cleaning-tasks" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
