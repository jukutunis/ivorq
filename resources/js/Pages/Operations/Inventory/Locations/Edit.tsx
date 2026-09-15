import AppLayout from '@/Layouts/AppLayout';
import { EnumOption, InventoryLocation } from '@/Types';
import { Link, useForm } from '@inertiajs/react';

interface Props { location: InventoryLocation; type_options: EnumOption[]; parent_locations: InventoryLocation[]; }

export default function LocationEdit({ location, type_options, parent_locations }: Props) {
    const { data, setData, put, processing, errors } = useForm({ name: location.name, type: location.type, parent_id: location.parent_id ?? '' });
    const submit = (e: React.FormEvent) => { e.preventDefault(); put(`/operations/inventory/locations/${location.id}`); };
    return <AppLayout>
        <Link href={`/operations/inventory/locations/${location.id}`} className="text-sm text-gray-500">← {location.name}</Link><h1 className="text-2xl font-bold my-6">Edit Location</h1>
        <form onSubmit={submit} className="max-w-2xl"><div className="bg-white rounded-lg shadow p-6 space-y-5">
            <div><label className="block text-sm font-medium mb-1">Name <span className="text-red-500">*</span></label><input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} className="border rounded px-3 py-2 text-sm w-full" />{errors.name && <p className="text-red-600 text-xs mt-1">{errors.name}</p>}</div>
            <div><label className="block text-sm font-medium mb-1">Type <span className="text-red-500">*</span></label><select value={data.type} onChange={(e) => setData('type', e.target.value)} className="border rounded px-3 py-2 text-sm w-full">{type_options.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select>{errors.type && <p className="text-red-600 text-xs mt-1">{errors.type}</p>}</div>
            <div><label className="block text-sm font-medium mb-1">Parent location</label><select value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)} className="border rounded px-3 py-2 text-sm w-full"><option value="">No parent</option>{parent_locations.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name}</option>)}</select>{errors.parent_id && <p className="text-red-600 text-xs mt-1">{errors.parent_id}</p>}</div>
        </div><div className="flex gap-3 mt-4"><button disabled={processing} className="bg-blue-600 text-white px-5 py-2 rounded text-sm">{processing ? 'Saving…' : 'Save Changes'}</button><Link href={`/operations/inventory/locations/${location.id}`} className="bg-gray-100 px-5 py-2 rounded text-sm">Cancel</Link></div></form>
    </AppLayout>;
}
