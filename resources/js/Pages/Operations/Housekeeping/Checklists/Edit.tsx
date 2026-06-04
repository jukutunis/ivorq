import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { CleaningChecklist, EnumOption } from '@/Types';

interface Props {
    checklist:  CleaningChecklist;
    task_types: EnumOption[];
}

export default function ChecklistEdit({ checklist, task_types }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name:        checklist.name,
        task_type:   checklist.task_type ? String(checklist.task_type.value) : '',
        description: checklist.description ?? '',
        is_active:   checklist.is_active,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/checklists/${checklist.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/checklists" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Checklists
                </Link>
                <span className="text-gray-300">/</span>
                <Link
                    href={`/operations/checklists/${checklist.id}`}
                    className="text-sm text-gray-500 hover:text-gray-700"
                >
                    {checklist.name}
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Checklist</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                maxLength={255}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.name && <p className="text-red-600 text-xs mt-1">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Task Type</label>
                            <select
                                value={data.task_type}
                                onChange={(e) => setData('task_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— General (all types) —</option>
                                {task_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.task_type && <p className="text-red-600 text-xs mt-1">{errors.task_type}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={3}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.description && <p className="text-red-600 text-xs mt-1">{errors.description}</p>}
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id="is_active"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="rounded border-gray-300"
                        />
                        <label htmlFor="is_active" className="text-sm text-gray-700">Active</label>
                    </div>

                    <div className="p-3 bg-blue-50 rounded border border-blue-200 text-sm text-blue-700">
                        To manage checklist items (add, edit, remove, reorder), use the{' '}
                        <Link
                            href={`/operations/checklists/${checklist.id}`}
                            className="underline hover:no-underline"
                        >
                            checklist detail page
                        </Link>.
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Save Changes'}
                    </button>
                    <Link
                        href={`/operations/checklists/${checklist.id}`}
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                    <Link
                        href={`/operations/checklists/${checklist.id}`}
                        method="delete"
                        as="button"
                        className="ml-auto text-red-600 hover:text-red-800 text-sm px-3 py-2"
                        onBefore={() => confirm('Delete this checklist? This action cannot be undone.')}
                    >
                        Delete Checklist
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
