import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Building2, Loader2, ChevronLeft } from 'lucide-react';

interface TenantProps {
    id: string;
    name: string;
    logo?: string;
}

export default function ForgotPassword({ status, tenant }: { status?: string; tenant?: TenantProps }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <AuthLayout title="Forgot Password">
            <a
                href="/login"
                className="flex items-center text-[13px] text-[#6B7685] hover:text-[#1F2937] mb-4 transition-colors w-fit"
            >
                <ChevronLeft className="w-4 h-4 mr-1" strokeWidth={2} />
                Back to Sign In
            </a>

            {tenant && (
                <div className="flex flex-col items-center mb-6 pb-6 border-b border-[#F0F2F5]">
                    <span className="text-[12px] font-medium text-[#6B7685] uppercase tracking-wider mb-2">Workspace</span>
                    {tenant.logo ? (
                        <img src={tenant.logo} alt={tenant.name} className="h-12 w-auto mb-3" />
                    ) : (
                        <div className="h-12 w-12 bg-[#F4F6F8] border border-[#E8ECF0] rounded-lg flex items-center justify-center mb-3 text-[#1A2356]">
                            <Building2 className="w-6 h-6" strokeWidth={2} />
                        </div>
                    )}
                    <h2 className="text-[16px] font-semibold text-[#1F2937] text-center">{tenant.name}</h2>
                </div>
            )}

            <h1 className="text-[20px] font-semibold text-center mb-2 text-[#1F2937]">Reset Password</h1>
            <p className="text-center text-[13px] text-[#6B7685] mb-6">
                Enter your email to receive a password reset link.
            </p>

            {status && (
                <div className="mb-4 text-[13px] text-[#047857] bg-[#ECFDF5] border border-[#A7F3D0] rounded px-3 py-2">
                    {status}
                </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label htmlFor="email" className="block text-[13px] font-medium text-[#1F2937] mb-1">Email</label>
                    <input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={e => setData('email', e.target.value)}
                        className={`block w-full border ${errors?.email ? 'border-[#DC2626] focus:ring-[#DC2626]' : 'border-[#E8ECF0] focus:ring-[#4F6BED]'} rounded px-3 py-2 text-[14px] focus:outline-none focus:ring-2`}
                        required
                    />
                    {errors.email && <p className="text-[#DC2626] text-[12px] mt-1">{errors.email}</p>}
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-[#4F6BED] text-white py-2.5 rounded font-medium text-[14px] hover:bg-opacity-90 disabled:opacity-70 transition-colors flex items-center justify-center h-[42px] mt-2"
                >
                    {processing ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                    ) : 'Send Reset Link'}
                </button>
            </form>
        </AuthLayout>
    );
}
