import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { RatePlan, PaginatedData, EnumOption } from '@/Types';

interface Props {
    rate_plans: PaginatedData<RatePlan>;
    plan_types: EnumOption[];
    filters:    { plan_type?: string; is_active?: string };
}

export default function RatePlanIndex({ rate_plans, plan_types, filters }: Props) {
    function applyFilter(field: string, value: string) {
        router.get('/operations/pms/rate-plans', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Rate Plans</h1>
                    <p className="text-sm text-gray-500 mt-1">{rate_plans.total} plan{rate_plans.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/pms" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Dashboard
                    </Link>
                    <Link href="/operations/pms/rate-plans/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                        New Rate Plan
                    </Link>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap gap-3 mb-4">
                <select
                    value={filters.plan_type ?? ''}
                    onChange={(e) => applyFilter('plan_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Plan Types</option>
                    {plan_types.map((t) => (
                        <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                    ))}
                </select>
                <select
                    value={filters.is_active ?? ''}
                    onChange={(e) => applyFilter('is_active', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {rate_plans.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No rate plans found.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Base Rate</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Currency</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rate_plans.data.map((plan) => (
                                <tr key={plan.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 font-mono text-xs text-gray-600">{plan.rate_code}</td>
                                    <td className="px-6 py-4 font-medium text-gray-900">{plan.rate_name}</td>
                                    <td className="px-6 py-4 text-gray-600">{plan.plan_type.label}</td>
                                    <td className="px-6 py-4 text-gray-700 font-medium">{plan.base_rate.toFixed(2)}</td>
                                    <td className="px-6 py-4 text-gray-600">{plan.currency}</td>
                                    <td className="px-6 py-4">
                                        {plan.is_active ? (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Active</span>
                                        ) : (
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Inactive</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={`/operations/pms/rate-plans/${plan.id}`} className="text-blue-600 hover:text-blue-800 text-sm">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                )}

                {rate_plans.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {rate_plans.current_page} of {rate_plans.last_page}</span>
                        <div className="flex gap-1">
                            {rate_plans.links.map((link, i) => (
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${link.active ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span key={i} className="px-3 py-1 rounded border border-gray-200 text-xs text-gray-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
