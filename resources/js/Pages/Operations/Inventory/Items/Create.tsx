import AppLayout from '@/Layouts/AppLayout';
import { InventoryCategory } from '@/Types';
import { Link, useForm } from '@inertiajs/react';

interface Props { categories: InventoryCategory[]; inventory_types: string[]; criticalities: string[]; }

export default function ItemCreate({ categories, inventory_types, criticalities }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        sku: '', name: '', category_id: '', inventory_type: '', criticality: 'low',
        is_batch_tracked: false, is_expiry_tracked: false, is_active: true, reorder_point: '0',
    });
    const submit = (e: React.FormEvent) => { e.preventDefault(); post('/operations/inventory/items'); };

    return <AppLayout>
        <Link href="/operations/inventory/items" className="text-sm text-gray-500">← Items</Link><h1 className="text-2xl font-bold my-6">New Item</h1>
        <form onSubmit={submit} className="max-w-3xl"><div className="bg-white rounded-lg shadow p-6 space-y-5">
            <div className="grid gap-4 md:grid-cols-2"><TextField label="SKU" value={data.sku} error={errors.sku} onChange={(value) => setData('sku', value.toUpperCase())} mono /><TextField label="Name" value={data.name} error={errors.name} onChange={(value) => setData('name', value)} /></div>
            <div className="grid gap-4 md:grid-cols-3">
                <Select label="Category" value={data.category_id} error={errors.category_id} onChange={(value) => setData('category_id', value)} options={categories.map((category) => ({ value: category.id, label: category.name }))} />
                <Select label="Inventory type" value={data.inventory_type} error={errors.inventory_type} onChange={(value) => setData('inventory_type', value)} options={inventory_types.map(option)} />
                <Select label="Criticality" value={data.criticality} error={errors.criticality} onChange={(value) => setData('criticality', value)} options={criticalities.map(option)} />
            </div>
            <TextField label="Reorder point" value={data.reorder_point} error={errors.reorder_point} onChange={(value) => setData('reorder_point', value)} type="number" />
            <div className="flex flex-wrap gap-6"><Check label="Batch tracked" checked={data.is_batch_tracked} onChange={(checked) => setData('is_batch_tracked', checked)} /><Check label="Expiry tracked" checked={data.is_expiry_tracked} onChange={(checked) => setData('is_expiry_tracked', checked)} /><Check label="Active" checked={data.is_active} onChange={(checked) => setData('is_active', checked)} /></div>
        </div><Actions processing={processing} href="/operations/inventory/items" label="Create Item" /></form>
    </AppLayout>;
}

const option = (value: string) => ({ value, label: value.replaceAll('_', ' ') });
function TextField({ label, value, error, onChange, mono = false, type = 'text' }: { label: string; value: string; error?: string; onChange: (value: string) => void; mono?: boolean; type?: string }) { return <div><label className="block text-sm font-medium mb-1">{label} <span className="text-red-500">*</span></label><input type={type} min={type === 'number' ? 0 : undefined} step={type === 'number' ? '0.0001' : undefined} value={value} onChange={(e) => onChange(e.target.value)} className={`border rounded px-3 py-2 text-sm w-full ${mono ? 'font-mono' : ''}`} />{error && <p className="text-red-600 text-xs mt-1">{error}</p>}</div>; }
function Select({ label, value, error, onChange, options }: { label: string; value: string; error?: string; onChange: (value: string) => void; options: { value: string; label: string }[] }) { return <div><label className="block text-sm font-medium mb-1">{label} <span className="text-red-500">*</span></label><select value={value} onChange={(e) => onChange(e.target.value)} className="border rounded px-3 py-2 text-sm w-full capitalize"><option value="">Select</option>{options.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>{error && <p className="text-red-600 text-xs mt-1">{error}</p>}</div>; }
function Check({ label, checked, onChange }: { label: string; checked: boolean; onChange: (checked: boolean) => void }) { return <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />{label}</label>; }
function Actions({ processing, href, label }: { processing: boolean; href: string; label: string }) { return <div className="flex gap-3 mt-4"><button disabled={processing} className="bg-blue-600 text-white px-5 py-2 rounded text-sm">{processing ? 'Saving…' : label}</button><Link href={href} className="bg-gray-100 px-5 py-2 rounded text-sm">Cancel</Link></div>; }
