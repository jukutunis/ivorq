import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';

export default function UnitCreate() {
    const { data, setData, post, processing, errors } = useForm({ code: '', name: '' });
    const submit = (e: React.FormEvent) => { e.preventDefault(); post('/operations/inventory/units'); };

    return <AppLayout>
        <Link href="/operations/inventory/units" className="text-sm text-gray-500">← Units</Link>
        <h1 className="text-2xl font-bold text-gray-900 my-6">New Unit</h1>
        <form onSubmit={submit} className="max-w-2xl"><div className="bg-white rounded-lg shadow p-6 grid gap-5 md:grid-cols-2">
            <div><label className="block text-sm font-medium mb-1">Code <span className="text-red-500">*</span></label><input value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} maxLength={255} className="border rounded px-3 py-2 text-sm w-full font-mono" />{errors.code && <p className="text-red-600 text-xs mt-1">{errors.code}</p>}</div>
            <div><label className="block text-sm font-medium mb-1">Name <span className="text-red-500">*</span></label><input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} className="border rounded px-3 py-2 text-sm w-full" />{errors.name && <p className="text-red-600 text-xs mt-1">{errors.name}</p>}</div>
        </div><div className="flex gap-3 mt-4"><button disabled={processing} className="bg-blue-600 text-white px-5 py-2 rounded text-sm">{processing ? 'Creating…' : 'Create Unit'}</button><Link href="/operations/inventory/units" className="bg-gray-100 px-5 py-2 rounded text-sm">Cancel</Link></div></form>
    </AppLayout>;
}
