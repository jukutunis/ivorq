import AppLayout from '@/Layouts/AppLayout';
import { InventoryCategory } from '@/Types';
import { Link, useForm } from '@inertiajs/react';

interface Props { parent_categories: InventoryCategory[]; }

export default function CategoryCreate({ parent_categories }: Props) {
    const { data, setData, post, processing, errors } = useForm({ name: '', description: '', parent_id: '' });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/operations/inventory/categories');
    }

    return (
        <AppLayout>
            <Link href="/operations/inventory/categories" className="text-sm text-gray-500 hover:text-gray-700">← Categories</Link>
            <h1 className="text-2xl font-bold text-gray-900 my-6">New Category</h1>
            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">
                    <Field label="Name" required error={errors.name}>
                        <input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} className="border border-gray-300 rounded px-3 py-2 text-sm w-full" />
                    </Field>
                    <Field label="Parent category" error={errors.parent_id}>
                        <select value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)} className="border border-gray-300 rounded px-3 py-2 text-sm w-full">
                            <option value="">No parent</option>
                            {parent_categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Description" error={errors.description}>
                        <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} maxLength={255} rows={3} className="border border-gray-300 rounded px-3 py-2 text-sm w-full resize-none" />
                    </Field>
                </div>
                <FormActions processing={processing} label="Create Category" />
            </form>
        </AppLayout>
    );
}

function Field({ label, required = false, error, children }: { label: string; required?: boolean; error?: string; children: React.ReactNode }) {
    return <div><label className="block text-sm font-medium text-gray-700 mb-1">{label}{required && <span className="text-red-500"> *</span>}</label>{children}{error && <p className="text-red-600 text-xs mt-1">{error}</p>}</div>;
}

function FormActions({ processing, label }: { processing: boolean; label: string }) {
    return <div className="flex gap-3 mt-4"><button type="submit" disabled={processing} className="bg-blue-600 text-white px-5 py-2 rounded text-sm disabled:opacity-60">{processing ? 'Saving…' : label}</button><Link href="/operations/inventory/categories" className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm">Cancel</Link></div>;
}
