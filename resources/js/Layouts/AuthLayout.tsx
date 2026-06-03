import { PropsWithChildren } from 'react';

export default function AuthLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-gray-50 flex flex-col items-center justify-center">
            <div className="mb-6">
                <span className="text-2xl font-bold text-gray-900">IVORQ</span>
            </div>
            <div className="w-full max-w-md bg-white rounded-lg shadow p-8">
                {children}
            </div>
        </div>
    );
}
