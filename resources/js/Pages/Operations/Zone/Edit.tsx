import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { Zone, EnumOption } from '@/Types';

interface Props {
    zone:       Zone;
    zone_types: EnumOption[];
    priorities: EnumOption[];
    statuses:   EnumOption[];
}

function statusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        draft:     'bg-gray-100 text-gray-700',
        active:    'bg-green-100 text-green-700',
        suspended: 'bg-yellow-100 text-yellow-700',
        archived:  'bg-red-100 text-red-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

export default function ZoneEdit({ zone, zone_types, priorities }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        zone_code:   zone.zone_code,
        zone_name:   zone.zone_name,
        zone_type:   String(zone.zone_type.value),
        priority:    String(zone.priority.value),
        description: zone.description ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/zones/${zone.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/zones" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Zones
                </Link>
                <span className="text-gray-300">/</span>
                <Link
                    href={`/operations/zones/${zone.id}`}
                    className="text-sm text-gray-500 hover:text-gray-700"
                >
                    {zone.zone_name}
                </Link>
            </div>

            <div className="flex items-center gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Edit Zone</h1>
                {statusBadge(zone.status)}
            </div>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="flex items-center gap-2 p-3 bg-gray-50 rounded border border-gray-200 text-sm text-gray-600">
                        <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        To change the zone status, use the status control on the zone detail page.
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Zone Code <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.zone_code}
                                onChange={(e) => setData('zone_code', e.target.value)}
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
                                <option value="">— No priority —</option>
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
                        {processing ? 'Saving…' : 'Save Changes'}
                    </button>
                    <Link
                        href={`/operations/zones/${zone.id}`}
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                    <Link
                        href={`/operations/zones/${zone.id}`}
                        method="delete"
                        as="button"
                        className="ml-auto text-red-600 hover:text-red-800 text-sm px-3 py-2"
                        onBefore={() => confirm('Delete this zone? This action cannot be undone.')}
                    >
                        Delete Zone
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
