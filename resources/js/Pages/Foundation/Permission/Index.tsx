import AppLayout from '@/Layouts/AppLayout';

interface Props {
    permissions: any[];
    grouped: Record<string, any[]>;
}

export default function PermissionIndex({ permissions, grouped }: Props) {
    return (
        <AppLayout>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Permissions</h1>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Permission list — UI coming in Sprint 01 completion.</p>
                <p className="text-gray-400 text-xs mt-1">
                    {permissions?.length ?? 0} permissions across {Object.keys(grouped ?? {}).length} groups.
                </p>
            </div>
        </AppLayout>
    );
}
