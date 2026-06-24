import { PropsWithChildren } from 'react';
import { Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

export default function AuthLayout({ children, title }: PropsWithChildren<{ title?: string }>) {
    return (
        <div className="min-h-screen flex flex-col md:flex-row bg-[#FFFFFF] text-[#1F2937] font-sans antialiased">
            {title && <Head title={title} />}

            {/* Left Brand/Context Zone */}
            <div className="relative w-full md:w-[45%] flex flex-col justify-between bg-[#1A2356] text-white p-8 md:p-12 overflow-hidden shrink-0">
                {/* Architectural Texture Layer */}
                <div
                    className="absolute inset-0 opacity-[0.03] pointer-events-none"
                    style={{
                        backgroundImage: `linear-gradient(#FFFFFF 1px, transparent 1px), linear-gradient(90deg, #FFFFFF 1px, transparent 1px)`,
                        backgroundSize: '40px 40px'
                    }}
                />
                <div className="absolute top-0 right-0 w-full h-full bg-gradient-to-br from-transparent to-[#11183B] opacity-50 pointer-events-none" />

                <div className="relative z-10">
                    <span className="block text-2xl font-bold tracking-[3px] text-white mb-16">IVORQ</span>

                    <div className="space-y-4">
                        <span className="inline-block text-[11px] font-semibold tracking-widest uppercase text-[#4F6BED] bg-[#4F6BED]/10 px-2 py-1 rounded">
                            Hospitality Operations Cloud
                        </span>
                        <h1 className="text-3xl lg:text-4xl font-medium leading-tight text-white max-w-md">
                            One connected workspace for hotel operations.
                        </h1>
                        <p className="text-[14px] text-[#A5B4FC] font-medium tracking-wide">
                            Front Desk &middot; Housekeeping &middot; Engineering &middot; Finance
                        </p>
                    </div>
                </div>

                <div className="relative z-10 flex items-center text-[12px] text-[#818CF8] font-medium mt-16 md:mt-0">
                    <ShieldCheck className="w-4 h-4 mr-2" strokeWidth={2} />
                    <span>Tenant-aware access &middot; Secure workspace session</span>
                </div>
            </div>

            {/* Right Authentication Zone */}
            <div className="flex-1 flex flex-col items-center justify-center bg-[#FFFFFF] p-8 md:p-12">
                <div className="w-full max-w-[420px]">
                    {children}
                </div>
            </div>
        </div>
    );
}
