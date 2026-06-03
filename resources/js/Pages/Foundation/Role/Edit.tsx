import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    role: any;
    permissions: any[];
}

export default function RoleEdit({ role, permissions }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/roles" className="text-sm text-gray-500 hover:text-gray-700">← Roles</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Role — {role?.name}</h1>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Edit role permissions form — UI coming in Sprint 01 completion.</p>
                <p className="text-gray-400 text-xs mt-1">{permissions?.length ?? 0} permissions available.</p>
            </div>
        </AppLayout>
    );
}
