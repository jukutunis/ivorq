import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { ZoneTemplate, PaginatedData, EnumOption } from '@/Types';

interface Props {
    templates: PaginatedData<ZoneTemplate>;
}

function typeBadge(zoneType: EnumOption) {
    const classes: Record<string, string> = {
        guest_accommodation: 'bg-blue-100 text-blue-700',
        public_area:         'bg-purple-100 text-purple-700',
        food_and_beverage:   'bg-orange-100 text-orange-700',
        recreation:          'bg-green-100 text-green-700',
        back_of_house:       'bg-slate-100 text-slate-700',
        custom:              'bg-teal-100 text-teal-700',
    };
    const cls = classes[String(zoneType.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {zoneType.label}
        </span>
    );
}

function priorityBadge(priority: EnumOption) {
    const classes: Record<string, string> = {
        '1': 'bg-red-100 text-red-700',
        '2': 'bg-orange-100 text-orange-700',
        '3': 'bg-yellow-100 text-yellow-700',
        '4': 'bg-blue-100 text-blue-700',
        '5': 'bg-gray-100 text-gray-700',
    };
    const cls = classes[String(priority.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {priority.label}
        </span>
    );
}

export default function ZoneTemplateIndex({ templates }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/zones" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Zones
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Zone Templates</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        {templates.total} template{templates.total !== 1 ? 's' : ''} total
                    </p>
                </div>
                <Link
                    href="/operations/zone-templates/create"
                    className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700"
                >
                    Add Template
                </Link>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {templates.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No templates found. Create a template to speed up zone creation.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Default Priority</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {templates.data.map((tpl) => (
                                <tr key={tpl.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4">
                                        <p className="font-medium text-gray-900">{tpl.template_name}</p>
                                        {tpl.description && (
                                            <p className="text-xs text-gray-400 mt-0.5 truncate max-w-xs">{tpl.description}</p>
                                        )}
                                    </td>
                                    <td className="px-6 py-4">{typeBadge(tpl.zone_type)}</td>
                                    <td className="px-6 py-4">{priorityBadge(tpl.default_priority)}</td>
                                    <td className="px-6 py-4">
                                        {tpl.is_active ? (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Active</span>
                                        ) : (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Inactive</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 text-right">
                                        <Link
                                            href={`/operations/zone-templates/${tpl.id}/edit`}
                                            className="text-blue-600 hover:text-blue-800 text-sm"
                                        >
                                            Edit
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {templates.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {templates.current_page} of {templates.last_page}</span>
                        <div className="flex gap-1">
                            {templates.links.map((link, i) => (
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
