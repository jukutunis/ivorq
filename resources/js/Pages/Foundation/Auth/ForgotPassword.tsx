import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <AuthLayout>
            <h1 className="text-xl font-bold text-center mb-2">Forgot Password</h1>
            <p className="text-sm text-gray-500 text-center mb-6">
                Enter your email to receive a password reset link.
            </p>

            {status && (
                <div className="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded p-3">
                    {status}
                </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700">Email</label>
                    <input
                        type="email"
                        value={data.email}
                        onChange={e => setData('email', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded px-3 py-2"
                        required
                    />
                    {errors.email && <p className="text-red-500 text-sm mt-1">{errors.email}</p>}
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 disabled:opacity-50"
                >
                    {processing ? 'Sending...' : 'Send Reset Link'}
                </button>
            </form>

            <div className="mt-4 text-center">
                <a href="/login" className="text-sm text-blue-600 hover:underline">Back to Login</a>
            </div>
        </AuthLayout>
    );
}
