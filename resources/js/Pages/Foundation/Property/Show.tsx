import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    property: any;
}

export default function PropertyShow({ property }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/properties" className="text-sm text-gray-500 hover:text-gray-700">← Properties</Link>
            </div>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{property?.name ?? 'Property'}</h1>
                <Link href={`/properties/${property?.id}/edit`} className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                    Edit
                </Link>
            </div>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Property detail — UI coming in Sprint 01 completion.</p>
                <pre className="mt-3 text-xs text-gray-400 bg-gray-50 p-3 rounded overflow-auto">
                    {JSON.stringify(property, null, 2)}
                </pre>
            </div>
        </AppLayout>
    );
}
