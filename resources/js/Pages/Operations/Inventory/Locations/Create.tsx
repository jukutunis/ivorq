import AppLayout from '@/Layouts/AppLayout';
import { EnumOption, InventoryLocation } from '@/Types';
import { Link, useForm } from '@inertiajs/react';

interface Props { type_options: EnumOption[]; parent_locations: InventoryLocation[]; }

export default function LocationCreate({ type_options, parent_locations }: Props) {
    const { data, setData, post, processing, errors } = useForm({ name: '', type: '', parent_id: '' });
    const submit = (e: React.FormEvent) => { e.preventDefault(); post('/operations/inventory/locations'); };

    return <AppLayout>
        <Link href="/operations/inventory/locations" className="text-sm text-gray-500">← Locations</Link>
        <h1 className="text-2xl font-bold my-6">New Location</h1>
        <form onSubmit={submit} className="max-w-2xl"><div className="bg-white rounded-lg shadow p-6 space-y-5">
            <Input label="Name" value={data.name} error={errors.name} onChange={(value) => setData('name', value)} />
            <div><label className="block text-sm font-medium mb-1">Type <span className="text-red-500">*</span></label><select value={data.type} onChange={(e) => setData('type', e.target.value)} className="border rounded px-3 py-2 text-sm w-full"><option value="">Select type</option>{type_options.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select>{errors.type && <p className="text-red-600 text-xs mt-1">{errors.type}</p>}</div>
            <div><label className="block text-sm font-medium mb-1">Parent location</label><select value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)} className="border rounded px-3 py-2 text-sm w-full"><option value="">No parent</option>{parent_locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select>{errors.parent_id && <p className="text-red-600 text-xs mt-1">{errors.parent_id}</p>}</div>
        </div><Actions processing={processing} href="/operations/inventory/locations" label="Create Location" /></form>
    </AppLayout>;
}

function Input({ label, value, error, onChange }: { label: string; value: string; error?: string; onChange: (value: string) => void }) { return <div><label className="block text-sm font-medium mb-1">{label} <span className="text-red-500">*</span></label><input value={value} onChange={(e) => onChange(e.target.value)} maxLength={255} className="border rounded px-3 py-2 text-sm w-full" />{error && <p className="text-red-600 text-xs mt-1">{error}</p>}</div>; }
function Actions({ processing, href, label }: { processing: boolean; href: string; label: string }) { return <div className="flex gap-3 mt-4"><button disabled={processing} className="bg-blue-600 text-white px-5 py-2 rounded text-sm">{processing ? 'Saving…' : label}</button><Link href={href} className="bg-gray-100 px-5 py-2 rounded text-sm">Cancel</Link></div>; }
