import AppLayout from '@/Layouts/AppLayout';

interface Props {
    user: any;
}

export default function ProfileShow({ user }: Props) {
    return (
        <AppLayout>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">My Profile</h1>
            <div className="bg-white rounded-lg shadow p-6">
                <div className="mb-4">
                    <p className="text-sm font-medium text-gray-500">Name</p>
                    <p className="text-gray-900">{user?.name}</p>
                </div>
                <div className="mb-4">
                    <p className="text-sm font-medium text-gray-500">Email</p>
                    <p className="text-gray-900">{user?.email}</p>
                </div>
                <div className="mb-4">
                    <p className="text-sm font-medium text-gray-500">Phone</p>
                    <p className="text-gray-900">{user?.phone ?? '—'}</p>
                </div>
                <p className="text-gray-400 text-sm mt-4">Profile edit form — UI coming in Sprint 01 completion.</p>
            </div>
        </AppLayout>
    );
}
