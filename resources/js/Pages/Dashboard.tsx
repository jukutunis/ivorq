import { Link, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { PageProps } from '@/Types';

interface PmsStats {
    arrivals_today:   number;
    departures_today: number;
    in_house_count:   number;
    available_rooms:  number;
}

interface Props {
    pmsStats: PmsStats | null;
}

const modules = [
    { label: 'Properties',   href: '/properties',  description: 'Manage hotel properties' },
    { label: 'Companies',    href: '/companies',    description: 'Manage hotel groups' },
    { label: 'Departments',  href: '/departments',  description: 'Manage departments and positions' },
    { label: 'Users',        href: '/users',        description: 'Manage user accounts' },
    { label: 'Roles',        href: '/roles',        description: 'Manage roles and permissions' },
    { label: 'Audit Log',    href: '/audit',        description: 'View system audit trail' },
    { label: 'Activity Log', href: '/activity',     description: 'View user activity feed' },
];

function StatCard({
    label,
    value,
    href,
    colorClass,
}: {
    label: string;
    value: number;
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

export default function Dashboard({ pmsStats }: Props) {
    const { auth } = usePage<PageProps>().props;

    return (
        <AppLayout>
            <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
            <p className="mt-1 text-gray-500">Welcome back, {auth.user?.name}</p>

            {/* PMS Metrics */}
            {pmsStats && (
                <div className="mt-8">
                    <div className="flex items-center justify-between mb-3">
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wider">
                            Front Desk
                        </h2>
                        <Link
                            href="/operations/pms"
                            className="text-xs text-blue-600 hover:text-blue-800"
                        >
                            PMS Dashboard →
                        </Link>
                    </div>
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <StatCard
                            label="Arrivals Today"
                            value={pmsStats.arrivals_today}
                            href="/operations/pms/reservations?status=confirmed"
                            colorClass="text-blue-600"
                        />
                        <StatCard
                            label="Departures Today"
                            value={pmsStats.departures_today}
                            href="/operations/pms/reservations?status=checked_in"
                            colorClass="text-purple-600"
                        />
                        <StatCard
                            label="In-House"
                            value={pmsStats.in_house_count}
                            href="/operations/pms/reservations?status=checked_in"
                            colorClass="text-green-600"
                        />
                        <StatCard
                            label="Available Rooms"
                            value={pmsStats.available_rooms}
                            href="/operations/rooms"
                            colorClass="text-gray-900"
                        />
                    </div>
                </div>
            )}

            {/* Foundation Modules */}
            <div className="mt-8">
                <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-3">
                    Modules
                </h2>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {modules.map(mod => (
                        <Link
                            key={mod.href}
                            href={mod.href}
                            className="bg-white rounded-lg shadow p-6 hover:shadow-md transition-shadow"
                        >
                            <h3 className="font-semibold text-gray-900">{mod.label}</h3>
                            <p className="text-sm text-gray-500 mt-1">{mod.description}</p>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
