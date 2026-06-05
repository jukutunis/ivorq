import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { WorkOrder, PreventiveMaintenanceTask, AssetRequest } from '@/Types';

interface Stats {
    total_work_orders: number;
    open_work_orders: number;
    pending_pms: number;
    open_requests: number;
}

interface Props {
    stats?: Stats;
    recent_work_orders?: { data: WorkOrder[] };
    upcoming_pms?: { data: PreventiveMaintenanceTask[] };
}

function StatCard({
    label,
    value,
    href,
    colorClass,
}: {
    label: string;
    value: number | string;
    href: string;
    colorClass: string;
}) {
    return (
        <Link href={href} className="bg-white rounded-lg shadow p-6 hover:shadow-md transition-shadow">
            <p className="text-sm text-gray-500 mb-1">{label}</p>
            <p className={`text-3xl font-bold ${colorClass}`}>{value}</p>
        </Link>
    );
}

export default function EngineeringDashboard({ stats, recent_work_orders, upcoming_pms }: Props) {
    const workOrders = recent_work_orders?.data ?? [];
    const pms = upcoming_pms?.data ?? [];

    const defaultStats = stats ?? {
        total_work_orders: 0,
        open_work_orders: 0,
        pending_pms: 0,
        open_requests: 0,
    };

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Engineering</h1>
                    <p className="text-sm text-gray-500 mt-1">Operations overview</p>
                </div>
                <div className="flex gap-2">
                    <Link href="/operations/engineering/work-orders" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Work Orders
                    </Link>
                    <Link href="/operations/engineering/preventive-maintenances" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Preventive Maintenance
                    </Link>
                    <Link href="/operations/engineering/asset-requests" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Asset Requests
                    </Link>
                    <Link href="/operations/engineering/checklists" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Checklists
                    </Link>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
                <StatCard
                    label="Total Work Orders"
                    value={defaultStats.total_work_orders}
                    href="/operations/engineering/work-orders"
                    colorClass="text-gray-900"
                />
                <StatCard
                    label="Open Work Orders"
                    value={defaultStats.open_work_orders}
                    href="/operations/engineering/work-orders?status=open"
                    colorClass="text-blue-600"
                />
                <StatCard
                    label="Pending PMs"
                    value={defaultStats.pending_pms}
                    href="/operations/engineering/preventive-maintenances"
                    colorClass="text-yellow-600"
                />
                <StatCard
                    label="Open Requests"
                    value={defaultStats.open_requests}
                    href="/operations/engineering/asset-requests?status=open"
                    colorClass="text-purple-600"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Recent Work Orders */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">Recent Work Orders</h2>
                        <Link
                            href="/operations/engineering/work-orders/create"
                            className="text-blue-600 hover:text-blue-800 text-xs"
                        >
                            New work order
                        </Link>
                    </div>

                    {workOrders.length === 0 ? (
                        <div className="px-6 py-8 text-center text-gray-400 text-sm">
                            No recent work orders.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Number</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {workOrders.map((wo) => (
                                    <tr key={wo.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/operations/engineering/work-orders/${wo.id}`}
                                                className="font-medium text-blue-600 hover:text-blue-800 text-xs block truncate"
                                            >
                                                {wo.work_order_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-600 truncate max-w-[150px]">
                                            {wo.title}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {typeof wo.status === 'object' ? wo.status.label : wo.status}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="px-6 py-3 border-t border-gray-100">
                        <Link href="/operations/engineering/work-orders" className="text-xs text-blue-600 hover:text-blue-800">
                            View all work orders →
                        </Link>
                    </div>
                </div>

                {/* Upcoming PMs */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-700">Upcoming PMs</h2>
                        <Link
                            href="/operations/engineering/preventive-maintenances/create"
                            className="text-blue-600 hover:text-blue-800 text-xs"
                        >
                            New PM
                        </Link>
                    </div>

                    {pms.length === 0 ? (
                        <div className="px-6 py-8 text-center text-gray-400 text-sm">
                            No upcoming PMs.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                    <th className="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {pms.map((pm) => (
                                    <tr key={pm.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/operations/engineering/preventive-maintenance-tasks/${pm.id}`}
                                                className="font-medium text-blue-600 hover:text-blue-800 text-xs"
                                            >
                                                {pm.title}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-600">
                                            {pm.due_date ? new Date(pm.due_date).toLocaleDateString() : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {typeof pm.status === 'object' ? pm.status.label : pm.status}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="px-6 py-3 border-t border-gray-100">
                        <Link href="/operations/engineering/preventive-maintenances" className="text-xs text-blue-600 hover:text-blue-800">
                            View all PMs →
                        </Link>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
