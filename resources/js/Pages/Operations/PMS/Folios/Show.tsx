import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm, router } from '@inertiajs/react';
import { Folio, FolioItem, EnumOption } from '@/Types';
import { useState } from 'react';
import axios from 'axios';

interface Props {
    folio: Folio;
}

const ITEM_TYPES: EnumOption[] = [
    { value: 'room_charge',    label: 'Room Charge' },
    { value: 'tax',            label: 'Tax' },
    { value: 'service_charge', label: 'Service Charge' },
    { value: 'adjustment',     label: 'Adjustment' },
    { value: 'payment',        label: 'Payment' },
    { value: 'deposit',        label: 'Deposit' },
    { value: 'other',          label: 'Other' },
];

function folioStatusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        open:   'bg-green-100 text-green-700',
        closed: 'bg-gray-100 text-gray-600',
        void:   'bg-red-100 text-red-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function PostItemForm({ folio, onSuccess }: { folio: Folio; onSuccess: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        item_type:   '',
        description: '',
        quantity:    '1',
        amount:      '',
        posted_at:   '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(`/operations/pms/folios/${folio.id}/items`, {
            onSuccess: () => { reset(); onSuccess(); },
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-xs text-gray-500 mb-1">
                        Item Type <span className="text-red-500">*</span>
                    </label>
                    <select
                        value={data.item_type}
                        onChange={(e) => setData('item_type', e.target.value)}
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="">— Select type —</option>
                        {ITEM_TYPES.map((t) => (
                            <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                        ))}
                    </select>
                    {errors.item_type && <p className="text-red-600 text-xs mt-1">{errors.item_type}</p>}
                </div>
                <div>
                    <label className="block text-xs text-gray-500 mb-1">
                        Description <span className="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="e.g. Room 101 – Night 1"
                        maxLength={255}
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.description && <p className="text-red-600 text-xs mt-1">{errors.description}</p>}
                </div>
                <div>
                    <label className="block text-xs text-gray-500 mb-1">Quantity</label>
                    <input
                        type="number"
                        value={data.quantity}
                        onChange={(e) => setData('quantity', e.target.value)}
                        min={0.01}
                        step={0.01}
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.quantity && <p className="text-red-600 text-xs mt-1">{errors.quantity}</p>}
                </div>
                <div>
                    <label className="block text-xs text-gray-500 mb-1">
                        Amount <span className="text-red-500">*</span>
                    </label>
                    <input
                        type="number"
                        value={data.amount}
                        onChange={(e) => setData('amount', e.target.value)}
                        step={0.01}
                        placeholder="e.g. 250.00"
                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    {errors.amount && <p className="text-red-600 text-xs mt-1">{errors.amount}</p>}
                </div>
            </div>
            <button
                type="submit"
                disabled={processing}
                className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-60"
            >
                {processing ? 'Posting…' : 'Post Item'}
            </button>
        </form>
    );
}

export default function FolioShow({ folio }: Props) {
    const [loading, setLoading]           = useState(false);
    const [showPostItem, setShowPostItem] = useState(false);
    const statusVal = String(folio.status.value);
    const items     = folio.items ?? folio.active_items ?? [];

    function doAction(url: string) {
        if (loading) return;
        setLoading(true);
        axios
            .post(url)
            .then(() => router.reload())
            .finally(() => setLoading(false));
    }

    function voidItem(itemId: string) {
        if (!confirm('Void this item?')) return;
        if (loading) return;
        setLoading(true);
        axios
            .post(`/operations/pms/folio-items/${itemId}/void`)
            .then(() => router.reload())
            .finally(() => setLoading(false));
    }

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/pms/folios" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Folios
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">{folio.folio_number}</h1>
                    {folioStatusBadge(folio.status)}
                </div>
            </div>

            {/* Folio Summary */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Folio Summary</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Reservation</p>
                        <p className="text-sm text-gray-700">
                            {folio.reservation ? (
                                <Link href={`/operations/pms/reservations/${folio.reservation.id}`} className="text-blue-600 hover:text-blue-800 font-mono text-xs">
                                    {folio.reservation.reservation_number}
                                </Link>
                            ) : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Guest</p>
                        <p className="text-sm text-gray-700">{folio.guest?.full_name ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Currency</p>
                        <p className="text-sm text-gray-700">{folio.currency}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Total Charges</p>
                        <p className="text-sm font-medium text-gray-900">{folio.currency} {folio.total_charges.toFixed(2)}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Total Payments</p>
                        <p className="text-sm font-medium text-green-700">{folio.currency} {folio.total_payments.toFixed(2)}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Balance</p>
                        <p className={`text-sm font-bold ${folio.balance > 0 ? 'text-red-600' : 'text-green-600'}`}>
                            {folio.currency} {folio.balance.toFixed(2)}
                        </p>
                    </div>
                </div>

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {folio.created_at}
                </div>
            </div>

            {/* Folio Actions */}
            {statusVal === 'open' && (
                <div className="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Actions</h2>
                    <div className="flex flex-wrap gap-3">
                        <button
                            onClick={() => setShowPostItem(!showPostItem)}
                            className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700"
                        >
                            Post Item
                        </button>
                        <button
                            onClick={() => {
                                if (confirm('Close this folio?')) doAction(`/operations/pms/folios/${folio.id}/close`);
                            }}
                            disabled={loading}
                            className="bg-gray-600 text-white px-4 py-2 rounded text-sm hover:bg-gray-700 disabled:opacity-60"
                        >
                            {loading ? '…' : 'Close Folio'}
                        </button>
                        <button
                            onClick={() => {
                                if (confirm('Void this folio? This cannot be undone.')) doAction(`/operations/pms/folios/${folio.id}/void`);
                            }}
                            disabled={loading}
                            className="bg-red-100 text-red-700 px-4 py-2 rounded text-sm hover:bg-red-200 disabled:opacity-60"
                        >
                            {loading ? '…' : 'Void Folio'}
                        </button>
                    </div>

                    {showPostItem && (
                        <div className="mt-4 pt-4 border-t border-gray-100">
                            <p className="text-sm font-medium text-gray-700 mb-3">Post Item to Folio</p>
                            <PostItemForm folio={folio} onSuccess={() => { setShowPostItem(false); router.reload(); }} />
                        </div>
                    )}
                </div>
            )}

            {/* Line Items */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">
                        Line Items
                        {items.length > 0 && (
                            <span className="ml-2 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs">{items.length}</span>
                        )}
                    </h2>
                </div>

                {items.length === 0 ? (
                    <div className="px-6 py-8 text-center text-gray-400 text-sm">No items posted yet.</div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                                <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Posted</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {items.map((item) => (
                                <tr key={item.id} className={`hover:bg-gray-50 ${item.is_void ? 'opacity-40' : ''}`}>
                                    <td className="px-6 py-4">
                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                            {item.item_type.label}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4 text-gray-700">
                                        {item.description}
                                        {item.is_void && (
                                            <span className="ml-2 text-xs text-red-500 font-medium">VOID</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 text-gray-600">{item.quantity}</td>
                                    <td className="px-6 py-4 text-right text-gray-900 font-medium">
                                        {folio.currency} {item.amount.toFixed(2)}
                                    </td>
                                    <td className="px-6 py-4 text-gray-500 text-xs">{item.posted_at ?? item.created_at}</td>
                                    <td className="px-6 py-4 text-right">
                                        {statusVal === 'open' && !item.is_void && (
                                            <button
                                                onClick={() => voidItem(item.id)}
                                                disabled={loading}
                                                className="text-red-600 hover:text-red-800 text-xs disabled:opacity-60"
                                            >
                                                Void
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <td colSpan={3} className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</td>
                                <td className="px-6 py-3 text-right text-sm font-bold text-gray-900">
                                    {folio.currency} {folio.total_charges.toFixed(2)}
                                </td>
                                <td colSpan={2}></td>
                            </tr>
                        </tfoot>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}
