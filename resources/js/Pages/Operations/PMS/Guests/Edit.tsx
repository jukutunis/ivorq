import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { Guest, EnumOption } from '@/Types';

interface Props {
    guest:       Guest;
    guest_types: EnumOption[];
}

export default function GuestEdit({ guest, guest_types }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        full_name:   guest.full_name,
        email:       guest.email ?? '',
        phone:       guest.phone ?? '',
        nationality: guest.nationality ?? '',
        id_type:     guest.id_type ?? '',
        id_number:   guest.id_number ?? '',
        guest_type:  String(guest.guest_type.value),
        vip_level:   guest.vip_level ? String(guest.vip_level) : '',
        notes:       guest.notes ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/pms/guests/${guest.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/guests" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Guests
                </Link>
                <span className="text-gray-300">/</span>
                <Link href={`/operations/pms/guests/${guest.id}`} className="text-sm text-gray-500 hover:text-gray-700">
                    {guest.guest_code}
                </Link>
            </div>

            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Guest</h1>

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">

                    <div className="grid grid-cols-2 gap-4">
                        <div className="col-span-2">
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Full Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.full_name}
                                onChange={(e) => setData('full_name', e.target.value)}
                                maxLength={255}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.full_name && <p className="text-red-600 text-xs mt-1">{errors.full_name}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                maxLength={255}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.email && <p className="text-red-600 text-xs mt-1">{errors.email}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input
                                type="text"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                maxLength={50}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.phone && <p className="text-red-600 text-xs mt-1">{errors.phone}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Guest Type</label>
                            <select
                                value={data.guest_type}
                                onChange={(e) => setData('guest_type', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">— Select type —</option>
                                {guest_types.map((t) => (
                                    <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                                ))}
                            </select>
                            {errors.guest_type && <p className="text-red-600 text-xs mt-1">{errors.guest_type}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">VIP Level (1–5)</label>
                            <select
                                value={data.vip_level}
                                onChange={(e) => setData('vip_level', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">None</option>
                                {[1, 2, 3, 4, 5].map((l) => (
                                    <option key={l} value={String(l)}>VIP {l}</option>
                                ))}
                            </select>
                            {errors.vip_level && <p className="text-red-600 text-xs mt-1">{errors.vip_level}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Nationality</label>
                            <input
                                type="text"
                                value={data.nationality}
                                onChange={(e) => setData('nationality', e.target.value)}
                                maxLength={100}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.nationality && <p className="text-red-600 text-xs mt-1">{errors.nationality}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">ID Type</label>
                            <input
                                type="text"
                                value={data.id_type}
                                onChange={(e) => setData('id_type', e.target.value)}
                                maxLength={50}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.id_type && <p className="text-red-600 text-xs mt-1">{errors.id_type}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">ID Number</label>
                        <input
                            type="text"
                            value={data.id_number}
                            onChange={(e) => setData('id_number', e.target.value)}
                            maxLength={100}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        {errors.id_number && <p className="text-red-600 text-xs mt-1">{errors.id_number}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={3}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        />
                        {errors.notes && <p className="text-red-600 text-xs mt-1">{errors.notes}</p>}
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Save Changes'}
                    </button>
                    <Link href={`/operations/pms/guests/${guest.id}`} className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">
                        Cancel
                    </Link>
                    <Link
                        href={`/operations/pms/guests/${guest.id}`}
                        method="delete"
                        as="button"
                        className="ml-auto text-red-600 hover:text-red-800 text-sm px-3 py-2"
                        onBefore={() => confirm('Delete this guest profile? This action cannot be undone.')}
                    >
                        Delete Guest
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
