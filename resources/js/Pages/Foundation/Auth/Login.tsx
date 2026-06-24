import { FormEvent, useState, useRef, useEffect } from 'react';
import { useForm, router } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Building2, ChevronLeft, Eye, EyeOff, Loader2 } from 'lucide-react';

interface LoginProps {
    step: 'cloud_name' | 'credentials' | 'property';
    tenant?: { id: string; name: string; logo: string | null };
    properties?: { id: string; name: string; code: string }[];
    errors?: Record<string, string>;
}

export default function Login({ step, tenant, properties, errors }: LoginProps) {
    return (
        <AuthLayout title="Sign In">
            <div aria-live="polite" className="sr-only">
                {Object.values(errors || {}).map((err, i) => (
                    <span key={i}>{err}</span>
                ))}
            </div>

            {step === 'cloud_name' && <CloudNameStep errors={errors} />}
            {step === 'credentials' && <CredentialsStep tenant={tenant} errors={errors} />}
            {step === 'property' && <PropertySelectionStep properties={properties} errors={errors} />}
        </AuthLayout>
    );
}

function CloudNameStep({ errors }: { errors: any }) {
    const { data, setData, post, processing } = useForm({
        cloud_name: '',
    });

    const inputRef = useRef<HTMLInputElement>(null);
    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/login/tenant');
    }

    return (
        <div className="w-full">
            <div className="mb-8">
                <h1 className="text-[20px] font-semibold text-[#1F2937] mb-2">Find your workspace</h1>
                <p className="text-[13px] text-[#6B7685]">Enter your Cloud Name to continue.</p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5">
                <div>
                    <label htmlFor="cloud_name" className="block text-[13px] font-medium text-[#1F2937] mb-1.5">
                        Cloud Name
                    </label>
                    <input
                        id="cloud_name"
                        type="text"
                        ref={inputRef}
                        value={data.cloud_name}
                        onChange={e => setData('cloud_name', e.target.value)}
                        className={`block w-full border ${errors?.cloud_name ? 'border-[#DC2626] focus:border-[#DC2626] focus:ring-1 focus:ring-[#DC2626]' : 'border-[#E8ECF0] focus:border-[#4F6BED] focus:ring-1 focus:ring-[#4F6BED]'} rounded px-3 py-2 text-[14px] outline-none transition-colors`}
                        placeholder="e.g. marriott"
                        required
                    />
                    {errors?.cloud_name && (
                        <p className="text-[#DC2626] text-[12px] mt-1.5 font-medium">{errors.cloud_name}</p>
                    )}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-[#4F6BED] text-white py-2 rounded font-medium text-[14px] hover:bg-opacity-90 disabled:opacity-70 transition-colors flex items-center justify-center h-[40px]"
                >
                    {processing ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                    ) : 'Continue'}
                </button>
            </form>
        </div>
    );
}

function CredentialsStep({ tenant, errors }: { tenant: any; errors: any }) {
    const { data, setData, post, processing } = useForm({
        email: '',
        password: '',
        device_name: 'web',
    });
    const [showPassword, setShowPassword] = useState(false);

    const inputRef = useRef<HTMLInputElement>(null);
    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    function handleBack() {
        router.delete('/login/tenant');
    }

    return (
        <div className="w-full">
            <button
                onClick={handleBack}
                type="button"
                className="flex items-center text-[12px] font-medium text-[#6B7685] hover:text-[#1F2937] mb-6 transition-colors"
            >
                <ChevronLeft className="w-3.5 h-3.5 mr-1" strokeWidth={2} />
                Change Cloud Name
            </button>

            <div className="mb-8">
                <span className="inline-block text-[10px] font-bold tracking-widest text-[#6B7685] uppercase mb-3">
                    Workspace Access
                </span>

                <div className="flex items-center mb-4">
                    {tenant?.logo ? (
                        <img src={tenant.logo} alt={tenant.name} className="h-10 w-auto mr-4" />
                    ) : (
                        <div className="h-12 w-12 bg-[#F4F6F8] border border-[#E8ECF0] rounded-lg flex items-center justify-center mr-4 text-[#1A2356] shrink-0">
                            <Building2 className="w-5 h-5" strokeWidth={2} />
                        </div>
                    )}
                    <h1 className="text-[20px] font-semibold text-[#1F2937] leading-tight">
                        {tenant?.name}
                    </h1>
                </div>

                <p className="text-[13px] text-[#6B7685]">
                    Sign in to continue to your operational workspace.
                </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5">
                <div>
                    <label htmlFor="email" className="block text-[13px] font-medium text-[#1F2937] mb-1.5">Email</label>
                    <input
                        id="email"
                        type="email"
                        ref={inputRef}
                        value={data.email}
                        onChange={e => setData('email', e.target.value)}
                        className={`block w-full border ${errors?.email ? 'border-[#DC2626] focus:border-[#DC2626] focus:ring-1 focus:ring-[#DC2626]' : 'border-[#E8ECF0] focus:border-[#4F6BED] focus:ring-1 focus:ring-[#4F6BED]'} rounded px-3 py-2 text-[14px] outline-none transition-colors`}
                        required
                    />
                    {errors?.email && (
                        <p className="text-[#DC2626] text-[12px] mt-1.5 font-medium">{errors.email}</p>
                    )}
                </div>
                <div>
                    <div className="flex justify-between items-baseline mb-1.5">
                        <label htmlFor="password" className="block text-[13px] font-medium text-[#1F2937]">Password</label>
                        <a href="/forgot-password" className="text-[12px] text-[#4F6BED] hover:underline font-medium">
                            Forgot password?
                        </a>
                    </div>
                    <div className="relative">
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            value={data.password}
                            onChange={e => setData('password', e.target.value)}
                            className="block w-full border border-[#E8ECF0] focus:border-[#4F6BED] focus:ring-1 focus:ring-[#4F6BED] rounded px-3 py-2 text-[14px] outline-none transition-colors pr-10"
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword(!showPassword)}
                            className="absolute inset-y-0 right-0 px-3 flex items-center text-[#9CA3AF] hover:text-[#1F2937] transition-colors"
                            aria-label={showPassword ? "Hide password" : "Show password"}
                        >
                            {showPassword ? (
                                <EyeOff className="w-4 h-4" strokeWidth={2} />
                            ) : (
                                <Eye className="w-4 h-4" strokeWidth={2} />
                            )}
                        </button>
                    </div>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-[#4F6BED] text-white py-2 rounded font-medium text-[14px] hover:bg-opacity-90 disabled:opacity-70 transition-colors flex items-center justify-center h-[40px] mt-2"
                >
                    {processing ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                    ) : 'Sign In'}
                </button>
            </form>
        </div>
    );
}

function PropertySelectionStep({ properties, errors }: { properties: any; errors: any }) {
    const { data, setData, post, processing } = useForm({
        property_id: properties?.[0]?.id || '',
    });

    const formRef = useRef<HTMLFormElement>(null);
    useEffect(() => {
        const firstRadio = formRef.current?.querySelector('input[type="radio"]') as HTMLElement;
        firstRadio?.focus();
    }, []);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/login/property');
    }

    return (
        <div className="w-full">
            <div className="mb-8">
                <h1 className="text-[20px] font-semibold text-[#1F2937] mb-2">Select Location</h1>
                <p className="text-[13px] text-[#6B7685]">Choose an operational workspace to continue.</p>
            </div>

            <form ref={formRef} onSubmit={handleSubmit} className="space-y-5">
                <div className="space-y-2">
                    {properties?.map((prop: any) => (
                        <label
                            key={prop.id}
                            className={`flex items-center p-4 border rounded-lg cursor-pointer transition-all ${data.property_id === prop.id ? 'border-[#4F6BED] bg-[#EFF6FF] ring-1 ring-[#4F6BED]' : 'border-[#E8ECF0] bg-white hover:border-[#4F6BED] hover:bg-[#F8FAFC]'}`}
                        >
                            <input
                                type="radio"
                                name="property_id"
                                value={prop.id}
                                checked={data.property_id === prop.id}
                                onChange={() => setData('property_id', prop.id)}
                                className="h-4 w-4 text-[#4F6BED] focus:ring-[#4F6BED] border-gray-300 focus:outline-none"
                            />
                            <div className="ml-4 flex-1">
                                <span className="block text-[14px] font-semibold text-[#1F2937]">{prop.name}</span>
                                <span className="block text-[12px] text-[#6B7685] tracking-wide mt-0.5">{prop.code}</span>
                            </div>
                        </label>
                    ))}
                </div>
                {errors?.property_id && (
                    <p className="text-[#DC2626] text-[12px] font-medium">{errors.property_id}</p>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-[#4F6BED] text-white py-2 rounded font-medium text-[14px] hover:bg-opacity-90 disabled:opacity-70 transition-colors flex items-center justify-center h-[40px] mt-4"
                >
                    {processing ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                    ) : 'Enter Workspace'}
                </button>
            </form>
        </div>
    );
}
