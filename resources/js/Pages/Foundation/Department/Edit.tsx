import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    department: any;
}

export default function DepartmentEdit({ department }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href={`/departments/${department?.id}`} className="text-sm text-gray-500 hover:text-gray-700">← {department?.name ?? 'Department'}</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Department</h1>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Edit department form — UI coming in Sprint 01 completion.</p>
            </div>
        </AppLayout>
    );
}
