import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    department: any;
    positions: any[];
}

export default function PositionIndex({ department, positions }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href={`/departments/${department?.id}`} className="text-sm text-gray-500 hover:text-gray-700">← {department?.name ?? 'Department'}</Link>
            </div>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Positions — {department?.name}</h1>
            </div>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Position list — UI coming in Sprint 01 completion.</p>
                <p className="text-gray-400 text-xs mt-1">{positions?.length ?? 0} records available.</p>
            </div>
        </AppLayout>
    );
}
