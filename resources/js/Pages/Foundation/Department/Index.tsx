import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface Props {
    departments: { data: any[] };
}

export default function DepartmentIndex({ departments }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Departments</h1>
                <Link href="/departments/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                    Add Department
                </Link>
            </div>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Department list — UI coming in Sprint 01 completion.</p>
                <p className="text-gray-400 text-xs mt-1">{departments?.data?.length ?? 0} records available.</p>
            </div>
        </AppLayout>
    );
}
