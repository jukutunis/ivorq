import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';

interface SecuritySession {
    id: string;
    channel: 'web' | 'api';
    device_name: string | null;
    ip_address: string | null;
    user_agent: string | null;
    property: string | null;
    last_active_at: string | null;
    created_at: string | null;
    is_current: boolean;
}

interface Props {
    user: any;
    sessions: SecuritySession[];
}

export default function ProfileShow({ user, sessions }: Props) {
    const revoke = (session: SecuritySession) => {
        if (window.confirm(`Revoke ${session.is_current ? 'your current' : 'this'} session?`)) {
            router.delete(`/profile/sessions/${session.id}`);
        }
    };

    const logoutAll = () => {
        if (window.confirm('Log out every web and API session?')) {
            router.post('/logout/all');
        }
    };

    return (
        <AppLayout>
            <div className="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Profile & Security</h1>
                    <p className="mt-1 text-sm text-gray-500">Review your identity details and authenticated sessions.</p>
                </div>
                <button type="button" onClick={logoutAll} className="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                    Log out all devices
                </button>
            </div>
            <div className="mb-6 rounded-lg bg-white p-6 shadow">
                <h2 className="mb-4 text-lg font-semibold text-gray-900">Identity</h2>
                <div className="mb-4">
                    <p className="text-sm font-medium text-gray-500">Name</p>
                    <p className="text-gray-900">{user?.name}</p>
                </div>
                <div className="mb-4">
                    <p className="text-sm font-medium text-gray-500">Email</p>
                    <p className="text-gray-900">{user?.email}</p>
                </div>
                <div className="mb-4">
                    <p className="text-sm font-medium text-gray-500">Phone</p>
                    <p className="text-gray-900">{user?.phone ?? '—'}</p>
                </div>
            </div>

            <section className="rounded-lg bg-white p-6 shadow" aria-labelledby="security-sessions-heading">
                <div className="mb-4">
                    <h2 id="security-sessions-heading" className="text-lg font-semibold text-gray-900">Security sessions</h2>
                    <p className="mt-1 text-sm text-gray-500">Only sessions belonging to your identity are shown. Network addresses are masked.</p>
                </div>
                <div className="space-y-3">
                    {sessions.length === 0 && <p className="text-sm text-gray-500">No active sessions were found.</p>}
                    {sessions.map((session) => (
                        <article key={session.id} className="rounded-lg border border-gray-200 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium text-gray-900">{session.device_name || (session.channel === 'api' ? 'API credential' : 'Web browser')}</span>
                                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs uppercase text-gray-600">{session.channel}</span>
                                        {session.is_current && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Current</span>}
                                    </div>
                                    <p className="mt-1 text-sm text-gray-600">{session.property || 'Property unavailable'} · {session.ip_address || 'Network unavailable'}</p>
                                    <p className="mt-1 max-w-3xl truncate text-xs text-gray-500">{session.user_agent || 'Client details unavailable'}</p>
                                    <p className="mt-2 text-xs text-gray-500">Last active {session.last_active_at ? new Date(session.last_active_at).toLocaleString() : 'not recorded'} · Evidence {session.id}</p>
                                </div>
                                <button type="button" onClick={() => revoke(session)} className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    Revoke
                                </button>
                            </div>
                        </article>
                    ))}
                </div>
            </section>
        </AppLayout>
    );
}
