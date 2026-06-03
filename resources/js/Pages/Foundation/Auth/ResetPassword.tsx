import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';

interface Props {
    token: string;
}

export default function ResetPassword({ token }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email: '',
        password: '',
        password_confirmation: '',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/reset-password');
    }

    return (
        <AuthLayout>
            <h1 className="text-xl font-bold text-center mb-6">Reset Password</h1>

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
                    <label className="block text-sm font-medium text-gray-700">New Password</label>
                    <input
                        type="password"
                        value={data.password}
                        onChange={e => setData('password', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded px-3 py-2"
                        required
                    />
                    {errors.password && <p className="text-red-500 text-sm mt-1">{errors.password}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">Confirm Password</label>
                    <input
                        type="password"
                        value={data.password_confirmation}
                        onChange={e => setData('password_confirmation', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded px-3 py-2"
                        required
                    />
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 disabled:opacity-50"
                >
                    {processing ? 'Resetting...' : 'Reset Password'}
                </button>
            </form>
        </AuthLayout>
    );
}
