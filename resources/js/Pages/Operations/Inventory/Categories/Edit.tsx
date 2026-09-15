import AppLayout from '@/Layouts/AppLayout';
import { InventoryCategory } from '@/Types';
import { Link, useForm } from '@inertiajs/react';

interface Props { category: InventoryCategory; parent_categories: InventoryCategory[]; }

export default function CategoryEdit({ category, parent_categories }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: category.name,
        description: category.description ?? '',
        parent_id: category.parent_id ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/inventory/categories/${category.id}`);
    }

    return (
        <AppLayout>
            <Link href={`/operations/inventory/categories/${category.id}`} className="text-sm text-gray-500 hover:text-gray-700">← {category.name}</Link>
            <h1 className="text-2xl font-bold text-gray-900 my-6">Edit Category</h1>
            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">
                    <div><label className="block text-sm font-medium text-gray-700 mb-1">Name <span className="text-red-500">*</span></label><input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} className="border border-gray-300 rounded px-3 py-2 text-sm w-full" />{errors.name && <p className="text-red-600 text-xs mt-1">{errors.name}</p>}</div>
                    <div><label className="block text-sm font-medium text-gray-700 mb-1">Parent category</label><select value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)} className="border border-gray-300 rounded px-3 py-2 text-sm w-full"><option value="">No parent</option>{parent_categories.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name}</option>)}</select>{errors.parent_id && <p className="text-red-600 text-xs mt-1">{errors.parent_id}</p>}</div>
                    <div><label className="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea value={data.description} onChange={(e) => setData('description', e.target.value)} maxLength={255} rows={3} className="border border-gray-300 rounded px-3 py-2 text-sm w-full resize-none" />{errors.description && <p className="text-red-600 text-xs mt-1">{errors.description}</p>}</div>
                </div>
                <div className="flex gap-3 mt-4"><button type="submit" disabled={processing} className="bg-blue-600 text-white px-5 py-2 rounded text-sm disabled:opacity-60">{processing ? 'Saving…' : 'Save Changes'}</button><Link href={`/operations/inventory/categories/${category.id}`} className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm">Cancel</Link></div>
            </form>
        </AppLayout>
    );
}
