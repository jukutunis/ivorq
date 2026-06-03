import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { PageProps } from '@/Types';

export default function AppLayout({ children }: PropsWithChildren) {
    const { auth, flash } = usePage<PageProps>().props;

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Nav */}
            <nav className="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
                <div className="flex items-center gap-6">
                    <Link href="/dashboard" className="font-bold text-gray-900 text-lg">
                        IVORQ
                    </Link>
                    <div className="flex gap-4 text-sm text-gray-600">
                        <Link href="/properties" className="hover:text-gray-900">Properties</Link>
                        <Link href="/departments" className="hover:text-gray-900">Departments</Link>
                        <Link href="/users" className="hover:text-gray-900">Users</Link>
                        <Link href="/roles" className="hover:text-gray-900">Roles</Link>
                        <Link href="/audit" className="hover:text-gray-900">Audit</Link>
                        <Link href="/activity" className="hover:text-gray-900">Activity</Link>
                    </div>
                </div>
                <div className="flex items-center gap-4 text-sm">
                    <Link href="/profile" className="text-gray-600 hover:text-gray-900">
                        {auth.user?.name}
                    </Link>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="text-gray-500 hover:text-gray-900"
                    >
                        Logout
                    </Link>
                </div>
            </nav>

            {/* Flash */}
            {flash?.success && (
                <div className="bg-green-50 border-b border-green-200 px-6 py-2 text-sm text-green-700">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="bg-red-50 border-b border-red-200 px-6 py-2 text-sm text-red-700">
                    {flash.error}
                </div>
            )}

            {/* Content */}
            <main className="px-6 py-8 max-w-7xl mx-auto">
                {children}
            </main>
        </div>
    );
}
