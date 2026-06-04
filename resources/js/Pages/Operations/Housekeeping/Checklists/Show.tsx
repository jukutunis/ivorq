import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm, router } from '@inertiajs/react';
import { CleaningChecklist, ChecklistItem, EnumOption } from '@/Types';
import { useState } from 'react';

interface Props {
    checklist: CleaningChecklist;
}

function activeBadge(isActive: boolean) {
    return isActive ? (
        <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">
            Active
        </span>
    ) : (
        <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">
            Inactive
        </span>
    );
}

function AddItemForm({ checklist }: { checklist: CleaningChecklist }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        item_text:   '',
        sort_order:  String((checklist.items?.length ?? 0)),
        is_required: false,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(`/operations/checklists/${checklist.id}/items`, { onSuccess: () => reset() });
    }

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid grid-cols-3 gap-3">
                <div className="col-span-2">
                    <input
                        type="text"
                        value={data.item_text}
                        onChange={(e) => setData('item_text', e.target.value)}
                        placeholder="Item description…"
                        maxLength={500}
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.item_text && <p className="text-red-600 text-xs mt-1">{errors.item_text}</p>}
                </div>
                <div className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        id="is_required_new"
                        checked={data.is_required}
                        onChange={(e) => setData('is_required', e.target.checked)}
                        className="rounded border-gray-300"
                    />
                    <label htmlFor="is_required_new" className="text-sm text-gray-600">Required</label>
                </div>
            </div>
            <button
                type="submit"
                disabled={processing || !data.item_text.trim()}
                className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
            >
                {processing ? 'Adding…' : 'Add Item'}
            </button>
        </form>
    );
}

function EditItemRow({
    item,
    checklistId,
    onDone,
}: {
    item: ChecklistItem;
    checklistId: string;
    onDone: () => void;
}) {
    const { data, setData, put, processing, errors } = useForm({
        item_text:   item.item_text,
        is_required: item.is_required,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/operations/checklists/${checklistId}/items/${item.id}`, { onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="flex items-center gap-2 w-full">
            <input
                type="text"
                value={data.item_text}
                onChange={(e) => setData('item_text', e.target.value)}
                maxLength={500}
                className="border border-gray-300 rounded px-2 py-1 text-sm flex-1 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <label className="flex items-center gap-1 text-xs text-gray-600 shrink-0">
                <input
                    type="checkbox"
                    checked={data.is_required}
                    onChange={(e) => setData('is_required', e.target.checked)}
                    className="rounded border-gray-300"
                />
                Req.
            </label>
            <button
                type="submit"
                disabled={processing}
                className="text-blue-600 hover:text-blue-800 text-xs shrink-0 disabled:opacity-60"
            >
                Save
            </button>
            <button type="button" onClick={onDone} className="text-gray-400 hover:text-gray-600 text-xs shrink-0">
                Cancel
            </button>
        </form>
    );
}

export default function ChecklistShow({ checklist }: Props) {
    const [editingItemId, setEditingItemId] = useState<string | null>(null);
    const [showAddForm, setShowAddForm]     = useState(false);
    const items = checklist.items ?? [];

    function deleteItem(itemId: string) {
        if (!confirm('Remove this item?')) return;
        router.delete(`/operations/checklists/${checklist.id}/items/${itemId}`, { preserveScroll: true });
    }

    function moveItem(itemId: string, direction: 'up' | 'down') {
        const idx = items.findIndex((i) => i.id === itemId);
        if (idx === -1) return;
        if (direction === 'up' && idx === 0) return;
        if (direction === 'down' && idx === items.length - 1) return;

        const newOrder = [...items.map((i) => i.id)];
        const swapIdx  = direction === 'up' ? idx - 1 : idx + 1;
        [newOrder[idx], newOrder[swapIdx]] = [newOrder[swapIdx], newOrder[idx]];

        router.post(
            `/operations/checklists/${checklist.id}/items/reorder`,
            { items: newOrder },
            { preserveScroll: true }
        );
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/checklists" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Checklists
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3">
                    <h1 className="text-2xl font-bold text-gray-900">{checklist.name}</h1>
                    {activeBadge(checklist.is_active)}
                </div>
                <Link
                    href={`/operations/checklists/${checklist.id}/edit`}
                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                >
                    Edit
                </Link>
            </div>

            {/* Checklist Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Details</h2>
                <div className="grid grid-cols-2 gap-6">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Task Type</p>
                        <p className="text-sm text-gray-700">
                            {checklist.task_type ? checklist.task_type.label : 'General (all types)'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Items</p>
                        <p className="text-sm text-gray-700">{items.length}</p>
                    </div>
                </div>
                {checklist.description && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Description</p>
                        <p className="text-sm text-gray-700">{checklist.description}</p>
                    </div>
                )}
            </div>

            {/* Items Management */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-700">
                        Checklist Items
                        {items.length > 0 && (
                            <span className="ml-2 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs">
                                {items.length}
                            </span>
                        )}
                    </h2>
                    <button
                        onClick={() => setShowAddForm(!showAddForm)}
                        className="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700"
                    >
                        {showAddForm ? 'Cancel' : 'Add Item'}
                    </button>
                </div>

                {showAddForm && (
                    <div className="px-6 py-4 border-b border-gray-100 bg-gray-50">
                        <AddItemForm checklist={checklist} />
                    </div>
                )}

                {items.length === 0 ? (
                    <div className="px-6 py-10 text-center text-gray-400 text-sm">
                        No items yet. Add items using the button above.
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {items.map((item, idx) => (
                            <li key={item.id} className="px-6 py-3 flex items-center gap-3 hover:bg-gray-50">
                                {/* Reorder controls */}
                                <div className="flex flex-col gap-0.5 shrink-0">
                                    <button
                                        onClick={() => moveItem(item.id, 'up')}
                                        disabled={idx === 0}
                                        className="text-gray-300 hover:text-gray-500 disabled:opacity-30 leading-none text-xs"
                                        title="Move up"
                                    >
                                        ▲
                                    </button>
                                    <button
                                        onClick={() => moveItem(item.id, 'down')}
                                        disabled={idx === items.length - 1}
                                        className="text-gray-300 hover:text-gray-500 disabled:opacity-30 leading-none text-xs"
                                        title="Move down"
                                    >
                                        ▼
                                    </button>
                                </div>

                                {/* Sort order number */}
                                <span className="text-xs text-gray-400 w-5 text-right shrink-0">{idx + 1}.</span>

                                {/* Item content or edit form */}
                                {editingItemId === item.id ? (
                                    <div className="flex-1">
                                        <EditItemRow
                                            item={item}
                                            checklistId={checklist.id}
                                            onDone={() => setEditingItemId(null)}
                                        />
                                    </div>
                                ) : (
                                    <>
                                        <div className="flex-1 min-w-0">
                                            <span className="text-sm text-gray-800">{item.item_text}</span>
                                            {item.is_required && (
                                                <span className="ml-2 text-xs text-red-500">required</span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-3 shrink-0">
                                            <button
                                                onClick={() => setEditingItemId(item.id)}
                                                className="text-blue-600 hover:text-blue-800 text-xs"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                onClick={() => deleteItem(item.id)}
                                                className="text-red-600 hover:text-red-800 text-xs"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
