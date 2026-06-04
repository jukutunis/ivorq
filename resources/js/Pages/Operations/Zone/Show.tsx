import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm, router } from '@inertiajs/react';
import { Zone, ZoneAssignment, ZoneHistory, EnumOption, PaginatedData } from '@/Types';
import { useState } from 'react';

interface Props {
    zone:        Zone;
    assignments: { data: ZoneAssignment[] };
    histories:   PaginatedData<ZoneHistory>;
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
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {priority.label}
        </span>
    );
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
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {zoneType.label}
        </span>
    );
}

function AssignmentForm({ zone }: { zone: Zone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id:       '',
        department_id: '',
        start_date:    '',
        end_date:      '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(`/operations/zones/${zone.id}/assignments`, {
            onSuccess: () => reset(),
        });
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
                    {errors.user_id && (
                        <p className="text-red-600 text-xs mt-1">{errors.user_id}</p>
                    )}
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
                    {errors.department_id && (
                        <p className="text-red-600 text-xs mt-1">{errors.department_id}</p>
                    )}
                </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        Start Date <span className="text-red-500">*</span>
                    </label>
                    <input
                        type="date"
                        value={data.start_date}
                        onChange={(e) => setData('start_date', e.target.value)}
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.start_date && (
                        <p className="text-red-600 text-xs mt-1">{errors.start_date}</p>
                    )}
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        End Date <span className="text-gray-400 font-normal">(optional)</span>
                    </label>
                    <input
                        type="date"
                        value={data.end_date}
                        onChange={(e) => setData('end_date', e.target.value)}
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.end_date && (
                        <p className="text-red-600 text-xs mt-1">{errors.end_date}</p>
                    )}
                </div>
            </div>
            <div>
                <button
                    type="submit"
                    disabled={processing}
                    className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                >
                    {processing ? 'Assigning…' : 'Assign Employee'}
                </button>
            </div>
        </form>
    );
}

export default function ZoneShow({ zone, assignments, histories }: Props) {
    const [showAssignForm, setShowAssignForm] = useState(false);
    const assignmentList = assignments?.data ?? [];
    const historyList    = histories?.data ?? [];

    function endAssignment(assignmentId: string) {
        if (!confirm('End this assignment?')) return;
        router.post(`/operations/zones/${zone.id}/assignments/${assignmentId}/end`, {}, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/zones" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Zones
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3">
                    <h1 className="text-2xl font-bold text-gray-900">{zone.zone_name}</h1>
                    {statusBadge(zone.status)}
                    {priorityBadge(zone.priority)}
                </div>
                <div className="flex gap-2">
                    <Link
                        href={`/operations/zones/${zone.id}/edit`}
                        className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Edit
                    </Link>
                </div>
            </div>

            {/* Zone Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Zone Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Code</p>
                        <p className="text-sm font-mono font-medium text-gray-900">{zone.zone_code}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Type</p>
                        {typeBadge(zone.zone_type)}
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Status</p>
                        {statusBadge(zone.status)}
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Priority</p>
                        {priorityBadge(zone.priority)}
                    </div>
                </div>

                {zone.description && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Description</p>
                        <p className="text-sm text-gray-700">{zone.description}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 flex items-center gap-6 text-xs text-gray-400">
                    <span>Created {zone.created_at}</span>
                    {zone.updated_at !== zone.created_at && (
                        <span>Updated {zone.updated_at}</span>
                    )}
                </div>
            </div>

            {/* Active Assignments */}
            <div className="bg-white rounded-lg shadow mb-6">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-700">
                        Active Assignments
                        {assignmentList.length > 0 && (
                            <span className="ml-2 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs">
                                {assignmentList.length}
                            </span>
                        )}
                    </h2>
                    <button
                        onClick={() => setShowAssignForm(!showAssignForm)}
                        className="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700"
                    >
                        {showAssignForm ? 'Cancel' : 'Assign Employee'}
                    </button>
                </div>

                {showAssignForm && (
                    <div className="px-6 py-4 border-b border-gray-100 bg-gray-50">
                        <p className="text-sm font-medium text-gray-700 mb-3">New Assignment</p>
                        <AssignmentForm zone={zone} />
                    </div>
                )}

                {assignmentList.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">
                        No active assignments for this zone.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Start Date</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">End Date</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {assignmentList.map((a) => (
                                <tr key={a.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 text-gray-900">
                                        {a.user?.name ?? <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {a.department?.name ?? <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4 text-gray-600">{a.start_date}</td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {a.end_date ?? <span className="text-gray-400">Ongoing</span>}
                                    </td>
                                    <td className="px-6 py-4 text-right">
                                        <button
                                            onClick={() => endAssignment(a.id)}
                                            className="text-red-600 hover:text-red-800 text-xs"
                                        >
                                            End
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* History */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">History</h2>
                </div>

                {historyList.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">
                        No history recorded yet.
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {historyList.map((h) => (
                            <li key={h.id} className="px-6 py-3 flex items-start gap-3">
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm text-gray-800 capitalize">
                                        {h.action.replace(/_/g, ' ')}
                                        {h.performer && (
                                            <span className="text-gray-500"> by {h.performer.name}</span>
                                        )}
                                    </p>
                                    {h.remarks && (
                                        <p className="text-xs text-gray-500 mt-0.5">{h.remarks}</p>
                                    )}
                                </div>
                                <span className="text-xs text-gray-400 flex-shrink-0">{h.created_at}</span>
                            </li>
                        ))}
                    </ul>
                )}

                {histories.last_page > 1 && (
                    <div className="px-6 py-3 border-t border-gray-100 flex justify-end gap-1">
                        {histories.links.map((link, i) => (
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
                )}
            </div>
        </AppLayout>
    );
}
