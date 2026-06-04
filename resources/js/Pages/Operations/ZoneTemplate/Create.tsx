import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption } from '@/Types';

interface Props {
    zone_types: EnumOption[];
    priorities: EnumOption[];
}

export default function ZoneTemplateCreate({ zone_types, priorities }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        template_name:    '',
        zone_type:        '',
        default_priority: '',
        description:      '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/zone-templates');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/zone-templates" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Zone Templates
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Create Zone Template</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Template Name <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.template_name}
                            onChange={(e) => setData('template_name', e.target.value)}
                            placeholder="e.g. Standard Guest Room"
                            maxLength={255}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.template_name && (
                            <p className="text-red-600 text-xs mt-1">{errors.template_name}</p>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Zone Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={data.zone_type}
                                onChange={(e) => setData('zone_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select type —</option>
                                {zone_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                            {errors.zone_type && (
                                <p className="text-red-600 text-xs mt-1">{errors.zone_type}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Default Priority
                            </label>
                            <select
                                value={data.default_priority}
                                onChange={(e) => setData('default_priority', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— No default priority —</option>
                                {priorities.map((p) => (
                                    <option key={String(p.value)} value={String(p.value)}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                            {errors.default_priority && (
                                <p className="text-red-600 text-xs mt-1">{errors.default_priority}</p>
                            )}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Description
                        </label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={3}
                            placeholder="Optional description of this template..."
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.description && (
                            <p className="text-red-600 text-xs mt-1">{errors.description}</p>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Creating…' : 'Create Template'}
                    </button>
                    <Link
                        href="/operations/zone-templates"
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
