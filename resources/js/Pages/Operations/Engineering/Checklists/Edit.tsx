import AppLayout from '@/Layouts/AppLayout';
import { useForm, Link } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { EngineeringChecklist } from '@/Types';

interface Props {
    checklist: EngineeringChecklist;
}

export default function ChecklistsEdit({ checklist }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        title: checklist.title || '',
        description: checklist.description || '',
        is_active: checklist.is_active,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/operations/engineering/checklists/${checklist.id}`);
    };

    return (
        <AppLayout>
            <div className="max-w-2xl mx-auto">
                <div className="flex items-center justify-between mb-6">
                    <h1 className="text-2xl font-bold text-gray-900">Edit Checklist</h1>
                    <Link href={`/operations/engineering/checklists/${checklist.id}`} className="text-sm text-gray-600 hover:text-gray-900">
                        Cancel
                    </Link>
                </div>

                <div className="bg-white rounded-lg shadow p-6">
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Title</label>
                            <input
                                type="text"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            />
                            {errors.title && <div className="text-red-600 text-xs mt-1">{errors.title}</div>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">Description</label>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={4}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            />
                            {errors.description && <div className="text-red-600 text-xs mt-1">{errors.description}</div>}
                        </div>

                        <div className="flex items-center">
                            <input
                                id="is_active"
                                type="checkbox"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                            />
                            <label htmlFor="is_active" className="ml-2 block text-sm text-gray-900">
                                Active
                            </label>
                        </div>

                        <div className="pt-4 flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 text-sm disabled:opacity-50"
                            >
                                {processing ? 'Saving...' : 'Save Changes'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
