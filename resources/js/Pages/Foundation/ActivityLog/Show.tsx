import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    log: any;
}

export default function ActivityLogShow({ log }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/activity" className="text-sm text-gray-500 hover:text-gray-700">← Activity Log</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Activity Entry</h1>
            <div className="bg-white rounded-lg shadow p-6 space-y-3">
                <p className="text-gray-900">{log?.description}</p>
                <div className="text-sm text-gray-500">
                    <span>{log?.causer_type} #{log?.causer_id}</span>
                    {log?.subject_type && (
                        <span className="ml-2">acted on {log.subject_type} #{log.subject_id}</span>
                    )}
                </div>
                {log?.properties && Object.keys(log.properties).length > 0 && (
                    <pre className="text-xs bg-gray-50 p-3 rounded overflow-auto max-h-48">
                        {JSON.stringify(log.properties, null, 2)}
                    </pre>
                )}
                <p className="text-gray-400 text-xs">{log?.created_at}</p>
            </div>
        </AppLayout>
    );
}
