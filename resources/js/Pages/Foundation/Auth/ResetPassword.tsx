import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Loader2 } from 'lucide-react';

interface Props {
    token: string;
    email: string;
    tenant: string;
}

export default function ResetPassword({ token, email, tenant }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        tenant,
        password: '',
        password_confirmation: '',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post(`/reset-password?signature=${new URLSearchParams(window.location.search).get('signature')}&expires=${new URLSearchParams(window.location.search).get('expires')}`);
    }

    return (
        <AuthLayout title="Set New Password">
            <h1 className="text-[20px] font-semibold text-center mb-2 text-[#1F2937]">Set New Password</h1>
            <p className="text-center text-[13px] text-[#6B7685] mb-6">
                Please choose a strong password for your account.
            </p>

            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="block text-[13px] font-medium text-[#1F2937] mb-1">Email</label>
                    <input
                        type="email"
                        value={data.email}
                        disabled
                        className="block w-full border border-[#E8ECF0] bg-[#F4F6F8] text-[#6B7685] rounded px-3 py-2 text-[14px] cursor-not-allowed"
                    />
                    {errors.email && <p className="text-[#DC2626] text-[12px] mt-1">{errors.email}</p>}
                </div>
                <div>
                    <label className="block text-[13px] font-medium text-[#1F2937] mb-1">New Password</label>
                    <input
                        type="password"
                        value={data.password}
                        onChange={e => setData('password', e.target.value)}
                        className={`block w-full border ${errors.password ? 'border-[#DC2626] focus:ring-[#DC2626]' : 'border-[#E8ECF0] focus:ring-[#4F6BED]'} rounded px-3 py-2 text-[14px] focus:outline-none focus:ring-2`}
                        required
                    />
                    {errors.password && <p className="text-[#DC2626] text-[12px] mt-1">{errors.password}</p>}
                </div>
                <div>
                    <label className="block text-[13px] font-medium text-[#1F2937] mb-1">Confirm Password</label>
                    <input
                        type="password"
                        value={data.password_confirmation}
                        onChange={e => setData('password_confirmation', e.target.value)}
                        className="block w-full border border-[#E8ECF0] rounded px-3 py-2 text-[14px] focus:outline-none focus:ring-2 focus:ring-[#4F6BED]"
                        required
                    />
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-[#4F6BED] text-white py-2.5 rounded font-medium text-[14px] hover:bg-opacity-90 disabled:opacity-70 transition-colors flex items-center justify-center h-[42px] mt-2"
                >
                    {processing ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                    ) : 'Reset Password'}
                </button>
            </form>
        </AuthLayout>
    );
}
