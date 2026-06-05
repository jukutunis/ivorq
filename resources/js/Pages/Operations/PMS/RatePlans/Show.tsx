import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { RatePlan } from '@/Types';

interface Props {
    rate_plan: RatePlan;
}

export default function RatePlanShow({ rate_plan }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/rate-plans" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Rate Plans
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{rate_plan.rate_name}</h1>
                    {rate_plan.is_active ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Active</span>
                    ) : (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Inactive</span>
                    )}
                </div>
                <Link
                    href={`/operations/pms/rate-plans/${rate_plan.id}/edit`}
                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                >
                    Edit
                </Link>
            </div>

            {/* Rate Plan Details */}
            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Rate Plan Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Rate Code</p>
                        <p className="text-sm font-mono text-gray-700">{rate_plan.rate_code}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Plan Type</p>
                        <p className="text-sm text-gray-700">{rate_plan.plan_type.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Base Rate</p>
                        <p className="text-sm font-bold text-gray-900">{rate_plan.currency} {rate_plan.base_rate.toFixed(2)}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Currency</p>
                        <p className="text-sm text-gray-700">{rate_plan.currency}</p>
                    </div>
                </div>

                {rate_plan.description && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Description</p>
                        <p className="text-sm text-gray-700">{rate_plan.description}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {rate_plan.created_at}
                </div>
            </div>
        </AppLayout>
    );
}
