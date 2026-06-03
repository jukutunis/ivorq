import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    user: any;
}

export default function UserShow({ user }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/users" className="text-sm text-gray-500 hover:text-gray-700">← Users</Link>
            </div>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{user?.name ?? 'User'}</h1>
                <Link href={`/users/${user?.id}/edit`} className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                    Edit
                </Link>
            </div>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">User detail — UI coming in Sprint 01 completion.</p>
            </div>
        </AppLayout>
    );
}
