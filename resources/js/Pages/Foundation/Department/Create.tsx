import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

export default function DepartmentCreate() {
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/departments" className="text-sm text-gray-500 hover:text-gray-700">← Departments</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Create Department</h1>
            <div className="bg-white rounded-lg shadow p-6">
                <p className="text-gray-400 text-sm">Create department form — UI coming in Sprint 01 completion.</p>
            </div>
        </AppLayout>
    );
}
