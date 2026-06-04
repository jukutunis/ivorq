import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { EnumOption, ZoneTemplate } from '@/Types';

interface Props {
    zone_types: EnumOption[];
    priorities: EnumOption[];
    templates:  { data: ZoneTemplate[] };
}

export default function ZoneCreate({ zone_types, priorities, templates }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        zone_code:   '',
        zone_name:   '',
        zone_type:   '',
        priority:    '',
        description: '',
        template_id: '',
    });

    function handleTemplateChange(templateId: string) {
        setData('template_id', templateId);
        if (!templateId) return;

        const tpl = templates.data.find((t) => t.id === templateId);
        if (!tpl) return;

        setData((prev) => ({
            ...prev,
            template_id: templateId,
            zone_type:   String(tpl.zone_type.value),
            priority:    String(tpl.default_priority.value),
        }));
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/zones');
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/zones" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Zones
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Create Zone</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    {templates.data.length > 0 && (
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Apply Template <span className="text-gray-400 font-normal">(optional)</span>
                            </label>
                            <select
                                value={data.template_id}
                                onChange={(e) => handleTemplateChange(e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— No template —</option>
                                {templates.data.map((tpl) => (
                                    <option key={tpl.id} value={tpl.id}>
                                        {tpl.template_name} ({tpl.zone_type.label})
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Zone Code <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.zone_code}
                                onChange={(e) => setData('zone_code', e.target.value)}
                                placeholder="e.g. ZONE-01"
                                maxLength={50}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.zone_code && (
                                <p className="text-red-600 text-xs mt-1">{errors.zone_code}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Zone Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.zone_name}
                                onChange={(e) => setData('zone_name', e.target.value)}
                                placeholder="e.g. Lobby Area"
                                maxLength={255}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.zone_name && (
                                <p className="text-red-600 text-xs mt-1">{errors.zone_name}</p>
                            )}
                        </div>
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
                                Priority
                            </label>
                            <select
                                value={data.priority}
                                onChange={(e) => setData('priority', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select priority —</option>
                                {priorities.map((p) => (
                                    <option key={String(p.value)} value={String(p.value)}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                            {errors.priority && (
                                <p className="text-red-600 text-xs mt-1">{errors.priority}</p>
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
                            placeholder="Optional description of this zone..."
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
                        {processing ? 'Creating…' : 'Create Zone'}
                    </button>
                    <Link
                        href="/operations/zones"
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
