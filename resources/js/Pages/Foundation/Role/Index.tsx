import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    roles: any[];
}

export default function RoleIndex({ roles }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Roles</h1>
                <Link href="/roles/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                    Add Role
                </Link>
            </div>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Role list — UI coming in Sprint 01 completion.</p>
                <p className="text-gray-400 text-xs mt-1">{roles?.length ?? 0} records available.</p>
            </div>
        </AppLayout>
    );
}
