import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        device_name: 'web',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <AuthLayout>
            <h1 className="text-xl font-bold text-center mb-6">Sign In</h1>
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
                <div>
                    <label className="block text-sm font-medium text-gray-700">Password</label>
                    <input
                        type="password"
                        value={data.password}
                        onChange={e => setData('password', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded px-3 py-2"
                        required
                    />
                    {errors.password && <p className="text-red-500 text-sm mt-1">{errors.password}</p>}
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 disabled:opacity-50"
                >
                    {processing ? 'Signing in...' : 'Sign In'}
                </button>
            </form>
            <div className="mt-4 text-center">
                <a href="/forgot-password" className="text-sm text-blue-600 hover:underline">
                    Forgot password?
                </a>
            </div>
        </AuthLayout>
    );
}
