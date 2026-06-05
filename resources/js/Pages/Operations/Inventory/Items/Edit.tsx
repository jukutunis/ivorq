import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { InventoryItem, InventoryCategory, InventoryUnit } from '@/Types';

interface Props {
    item:       InventoryItem;
    categories: InventoryCategory[];
    units:      InventoryUnit[];
}

export default function ItemEdit({ item, categories, units }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        item_code:        item.item_code,
        name:             item.name,
        description:      item.description ?? '',
        sku:              (item as any).sku ?? '',
        barcode:          (item as any).barcode ?? '',
        category_id:      item.category_id,
        unit_id:          item.unit_id,
        min_stock:        String((item as any).min_stock ?? ''),
        max_stock:        String((item as any).max_stock ?? ''),
        reorder_point:    String(item.reorder_point ?? ''),
        reorder_quantity: String((item as any).reorder_quantity ?? ''),
        is_active:        item.is_active,
        notes:            (item as any).notes ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/inventory/items/${item.id}`);
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href={`/operations/inventory/items/${item.id}`} className="text-sm text-gray-500 hover:text-gray-700">← {item.name}</Link>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Item</h1>

            <form onSubmit={submit} className="max-w-3xl">
                <div className="bg-white rounded-lg shadow p-6 space-y-5">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">Basic Information</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Item Code <span className="text-red-500">*</span></label>
                            <input type="text" value={data.item_code}
                                onChange={(e) => setData('item_code', e.target.value.toUpperCase())}
                                maxLength={30}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono" />
                            {errors.item_code && <p className="text-red-600 text-xs mt-1">{errors.item_code}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Name <span className="text-red-500">*</span></label>
                            <input type="text" value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                maxLength={150}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.name && <p className="text-red-600 text-xs mt-1">{errors.name}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Category <span className="text-red-500">*</span></label>
                            <select value={data.category_id}
                                onChange={(e) => setData('category_id', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">— Select category —</option>
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                            {errors.category_id && <p className="text-red-600 text-xs mt-1">{errors.category_id}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Unit of Measure <span className="text-red-500">*</span></label>
                            <select value={data.unit_id}
                                onChange={(e) => setData('unit_id', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">— Select unit —</option>
                                {units.map((u) => (
                                    <option key={u.id} value={u.id}>{u.name} ({u.abbreviation})</option>
                                ))}
                            </select>
                            {errors.unit_id && <p className="text-red-600 text-xs mt-1">{errors.unit_id}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={2}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none" />
                        {errors.description && <p className="text-red-600 text-xs mt-1">{errors.description}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">SKU</label>
                            <input type="text" value={data.sku}
                                onChange={(e) => setData('sku', e.target.value)}
                                maxLength={100}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono" />
                            {errors.sku && <p className="text-red-600 text-xs mt-1">{errors.sku}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Barcode</label>
                            <input type="text" value={data.barcode}
                                onChange={(e) => setData('barcode', e.target.value)}
                                maxLength={100}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono" />
                            {errors.barcode && <p className="text-red-600 text-xs mt-1">{errors.barcode}</p>}
                        </div>
                    </div>

                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider pt-2 border-t border-gray-100">Stock Levels</h2>

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Min Stock</label>
                            <input type="number" step="0.001" min="0" value={data.min_stock}
                                onChange={(e) => setData('min_stock', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.min_stock && <p className="text-red-600 text-xs mt-1">{errors.min_stock}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Max Stock</label>
                            <input type="number" step="0.001" min="0" value={data.max_stock}
                                onChange={(e) => setData('max_stock', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.max_stock && <p className="text-red-600 text-xs mt-1">{errors.max_stock}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Reorder Point</label>
                            <input type="number" step="0.001" min="0" value={data.reorder_point}
                                onChange={(e) => setData('reorder_point', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.reorder_point && <p className="text-red-600 text-xs mt-1">{errors.reorder_point}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Reorder Qty</label>
                            <input type="number" step="0.001" min="0" value={data.reorder_quantity}
                                onChange={(e) => setData('reorder_quantity', e.target.value)}
                                className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            {errors.reorder_quantity && <p className="text-red-600 text-xs mt-1">{errors.reorder_quantity}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={2}
                            className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none" />
                        {errors.notes && <p className="text-red-600 text-xs mt-1">{errors.notes}</p>}
                    </div>

                    <div className="flex items-center gap-2">
                        <input id="is_active" type="checkbox" checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="h-4 w-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500" />
                        <label htmlFor="is_active" className="text-sm text-gray-700">Active</label>
                    </div>
                </div>

                <div className="flex items-center gap-3 mt-4">
                    <button type="submit" disabled={processing}
                        className="bg-blue-600 text-white px-5 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save Changes'}
                    </button>
                    <Link href={`/operations/inventory/items/${item.id}`} className="bg-gray-100 text-gray-700 px-5 py-2 rounded text-sm hover:bg-gray-200">Cancel</Link>
                </div>
            </form>
        </AppLayout>
    );
}
