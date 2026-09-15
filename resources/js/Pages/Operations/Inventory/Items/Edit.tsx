import AppLayout from '@/Layouts/AppLayout';
import { InventoryCategory, InventoryItem } from '@/Types';
import { Link, useForm } from '@inertiajs/react';

interface Props { item: InventoryItem; categories: InventoryCategory[]; inventory_types: string[]; criticalities: string[]; }

export default function ItemEdit({ item, categories, inventory_types, criticalities }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        sku: item.sku, name: item.name, category_id: item.category_id, inventory_type: item.inventory_type,
        criticality: item.criticality, is_batch_tracked: item.is_batch_tracked,
        is_expiry_tracked: item.is_expiry_tracked, is_active: item.is_active, reorder_point: String(item.reorder_point),
    });
    const submit = (e: React.FormEvent) => { e.preventDefault(); put(`/operations/inventory/items/${item.id}`); };
    const label = (value: string) => value.replaceAll('_', ' ');

    return <AppLayout>
        <Link href={`/operations/inventory/items/${item.id}`} className="text-sm text-gray-500">← {item.name}</Link><h1 className="text-2xl font-bold my-6">Edit Item</h1>
        <form onSubmit={submit} className="max-w-3xl"><div className="bg-white rounded-lg shadow p-6 space-y-5">
            <div className="grid gap-4 md:grid-cols-2"><div><label className="block text-sm font-medium mb-1">SKU</label><input value={data.sku} onChange={(e) => setData('sku', e.target.value.toUpperCase())} className="border rounded px-3 py-2 text-sm w-full font-mono" />{errors.sku && <p className="text-red-600 text-xs">{errors.sku}</p>}</div><div><label className="block text-sm font-medium mb-1">Name</label><input value={data.name} onChange={(e) => setData('name', e.target.value)} className="border rounded px-3 py-2 text-sm w-full" />{errors.name && <p className="text-red-600 text-xs">{errors.name}</p>}</div></div>
            <div className="grid gap-4 md:grid-cols-3"><div><label className="block text-sm font-medium mb-1">Category</label><select value={data.category_id} onChange={(e) => setData('category_id', e.target.value)} className="border rounded px-3 py-2 text-sm w-full">{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select>{errors.category_id && <p className="text-red-600 text-xs">{errors.category_id}</p>}</div><div><label className="block text-sm font-medium mb-1">Inventory type</label><select value={data.inventory_type} onChange={(e) => setData('inventory_type', e.target.value)} className="border rounded px-3 py-2 text-sm w-full capitalize">{inventory_types.map((value) => <option key={value} value={value}>{label(value)}</option>)}</select>{errors.inventory_type && <p className="text-red-600 text-xs">{errors.inventory_type}</p>}</div><div><label className="block text-sm font-medium mb-1">Criticality</label><select value={data.criticality} onChange={(e) => setData('criticality', e.target.value)} className="border rounded px-3 py-2 text-sm w-full capitalize">{criticalities.map((value) => <option key={value} value={value}>{label(value)}</option>)}</select>{errors.criticality && <p className="text-red-600 text-xs">{errors.criticality}</p>}</div></div>
            <div><label className="block text-sm font-medium mb-1">Reorder point</label><input type="number" min="0" step="0.0001" value={data.reorder_point} onChange={(e) => setData('reorder_point', e.target.value)} className="border rounded px-3 py-2 text-sm w-48" />{errors.reorder_point && <p className="text-red-600 text-xs">{errors.reorder_point}</p>}</div>
            <div className="flex flex-wrap gap-6">{([['is_batch_tracked', 'Batch tracked'], ['is_expiry_tracked', 'Expiry tracked'], ['is_active', 'Active']] as const).map(([key, text]) => <label key={key} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={data[key]} onChange={(e) => setData(key, e.target.checked)} />{text}</label>)}</div>
        </div><div className="flex gap-3 mt-4"><button disabled={processing} className="bg-blue-600 text-white px-5 py-2 rounded text-sm">{processing ? 'Saving…' : 'Save Changes'}</button><Link href={`/operations/inventory/items/${item.id}`} className="bg-gray-100 px-5 py-2 rounded text-sm">Cancel</Link></div></form>
    </AppLayout>;
}
