import { usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { PageProps } from '@/Types';

const modules = [
    { label: 'Properties',   href: '/properties',  description: 'Manage hotel properties' },
    { label: 'Companies',    href: '/companies',    description: 'Manage hotel groups' },
    { label: 'Departments',  href: '/departments',  description: 'Manage departments and positions' },
    { label: 'Users',        href: '/users',        description: 'Manage user accounts' },
    { label: 'Roles',        href: '/roles',        description: 'Manage roles and permissions' },
    { label: 'Audit Log',    href: '/audit',        description: 'View system audit trail' },
    { label: 'Activity Log', href: '/activity',     description: 'View user activity feed' },
];

export default function Dashboard() {
    const { auth } = usePage<PageProps>().props;

    return (
        <AppLayout>
            <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
            <p className="mt-1 text-gray-500">Welcome back, {auth.user?.name}</p>

            <div className="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
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
        </AppLayout>
    );
}
