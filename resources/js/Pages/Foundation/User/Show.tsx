import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Membership {
    property_id: string;
    name: string;
    status: string;
    is_default: boolean;
    is_current: boolean;
}

interface Props {
    user: any;
    memberships: Membership[];
}

export default function UserShow({ user, memberships }: Props) {
    const [replacementPropertyId, setReplacementPropertyId] = useState('');
    const activeAlternatives = memberships.filter((membership) => membership.status === 'active' && !membership.is_current);
    const command = (action: 'activate' | 'deactivate' | 'offboard') => {
        if (window.confirm(`${action[0].toUpperCase()}${action.slice(1)} ${user.name}?`)) {
            router.post(`/users/${user.id}/${action}`);
        }
    };
    const removeCurrentMembership = () => {
        if (window.confirm(`Remove ${user.name} from the current Property?`)) {
            router.delete(`/users/${user.id}/memberships/current`, {
                data: { replacement_property_id: replacementPropertyId || null },
            });
        }
    };
    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/users" className="text-sm text-gray-500 hover:text-gray-700">← Users</Link>
            </div>
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">{user?.name ?? 'User'}</h1>
                    <p className="mt-1 text-sm text-gray-500">{user?.email} · {user?.is_active ? 'Active' : 'Inactive'}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href={`/users/${user?.id}/edit`} className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Edit profile fields</Link>
                    {user?.is_active ? (
                        <button type="button" onClick={() => command('deactivate')} className="rounded border border-amber-300 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-50">Deactivate</button>
                    ) : (
                        <button type="button" onClick={() => command('activate')} className="rounded border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-50">Reactivate</button>
                    )}
                    <button type="button" onClick={() => command('offboard')} className="rounded border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Offboard</button>
                </div>
            </div>
            <div className="rounded-lg bg-white p-6 shadow">
                <h2 className="mb-4 text-lg font-semibold text-gray-900">Property memberships</h2>
                <div className="space-y-3">
                    {memberships.map((membership) => (
                        <div key={membership.property_id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 p-3">
                            <div>
                                <p className="font-medium text-gray-900">{membership.name}</p>
                                <p className="text-sm text-gray-500">{membership.status}{membership.is_current ? ' · Current Property' : ''}{membership.is_default ? ' · Default' : ''}</p>
                            </div>
                            {membership.status === 'active' && !membership.is_default && (
                                <button type="button" onClick={() => router.post(`/users/${user.id}/default-property`, { property_id: membership.property_id })} className="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Make default</button>
                            )}
                        </div>
                    ))}
                </div>
                {memberships.some((membership) => membership.is_current && membership.status === 'active') && (
                    <div className="mt-6 border-t border-gray-200 pt-4">
                        <label className="block text-sm font-medium text-gray-700" htmlFor="replacement-property">Replacement default, when required</label>
                        <select id="replacement-property" value={replacementPropertyId} onChange={(event) => setReplacementPropertyId(event.target.value)} className="mt-1 w-full max-w-md rounded-md border-gray-300 text-sm">
                            <option value="">No replacement</option>
                            {activeAlternatives.map((membership) => <option key={membership.property_id} value={membership.property_id}>{membership.name}</option>)}
                        </select>
                        <button type="button" onClick={removeCurrentMembership} className="mt-3 rounded border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Remove current Property membership</button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
