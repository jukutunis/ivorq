import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { Zone } from '@/Types';

interface SelectOption {
    id:   string;
    name: string;
}

interface Props {
    zone:        Zone;
    users?:      SelectOption[];
    departments?: SelectOption[];
}

export default function ZoneAssignmentCreate({ zone, users = [], departments = [] }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        user_id:       '',
        department_id: '',
        start_date:    '',
        end_date:      '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(`/operations/zones/${zone.id}/assignments`);
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

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Assign Employee</h1>

            <form onSubmit={submit} className="max-w-lg">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="flex items-center gap-3 p-3 bg-blue-50 rounded border border-blue-100 text-sm text-blue-700">
                        <svg className="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        </svg>
                        Assigning to: <strong className="font-semibold">{zone.zone_name}</strong>
                        <span className="font-mono text-xs text-blue-500">({zone.zone_code})</span>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Employee <span className="text-red-500">*</span>
                        </label>
                        {users.length > 0 ? (
                            <select
                                value={data.user_id}
                                onChange={(e) => setData('user_id', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select employee —</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>{u.name}</option>
                                ))}
                            </select>
                        ) : (
                            <input
                                type="text"
                                value={data.user_id}
                                onChange={(e) => setData('user_id', e.target.value)}
                                placeholder="User ULID"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        )}
                        {errors.user_id && (
                            <p className="text-red-600 text-xs mt-1">{errors.user_id}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Department <span className="text-red-500">*</span>
                        </label>
                        {departments.length > 0 ? (
                            <select
                                value={data.department_id}
                                onChange={(e) => setData('department_id', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select department —</option>
                                {departments.map((d) => (
                                    <option key={d.id} value={d.id}>{d.name}</option>
                                ))}
                            </select>
                        ) : (
                            <input
                                type="text"
                                value={data.department_id}
                                onChange={(e) => setData('department_id', e.target.value)}
                                placeholder="Department ULID"
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        )}
                        {errors.department_id && (
                            <p className="text-red-600 text-xs mt-1">{errors.department_id}</p>
                        )}
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
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Assigning…' : 'Assign Employee'}
                    </button>
                    <Link
                        href={`/operations/zones/${zone.id}`}
                        className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
