import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    log: any;
}

export default function AuditLogShow({ log }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/audit" className="text-sm text-gray-500 hover:text-gray-700">← Audit Log</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">
                Audit Entry — <span className="capitalize text-blue-600">{log?.event}</span>
            </h1>
            <div className="bg-white rounded-lg shadow p-6 space-y-4">
                <div>
                    <p className="text-xs font-medium text-gray-400 uppercase">Model</p>
                    <p className="text-gray-800">{log?.auditable_type} <span className="text-gray-400">#{log?.auditable_id}</span></p>
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <p className="text-xs font-medium text-gray-400 uppercase mb-1">Before</p>
                        <pre className="text-xs bg-gray-50 p-3 rounded overflow-auto max-h-64">
                            {JSON.stringify(log?.old_values, null, 2) ?? '—'}
                        </pre>
                    </div>
                    <div>
                        <p className="text-xs font-medium text-gray-400 uppercase mb-1">After</p>
                        <pre className="text-xs bg-gray-50 p-3 rounded overflow-auto max-h-64">
                            {JSON.stringify(log?.new_values, null, 2) ?? '—'}
                        </pre>
                    </div>
                </div>
                <p className="text-gray-400 text-xs">{log?.created_at}</p>
            </div>
        </AppLayout>
    );
}
