import AppLayout from '@/Layouts/AppLayout';

interface Props {
    logs: { data: any[] };
    filters: Record<string, string>;
}

export default function ActivityLogIndex({ logs, filters }: Props) {
    return (
        <AppLayout>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Activity Log</h1>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Activity log feed — UI coming in Sprint 01 completion.</p>
                <p className="text-gray-400 text-xs mt-1">{logs?.data?.length ?? 0} records on this page.</p>
            </div>
        </AppLayout>
    );
}
