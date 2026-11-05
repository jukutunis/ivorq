import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface ReceiptException {
    id: string;
    goods_receipt_line_id: string;
    discrepancy_type: string | null;
    inspection_result: string | null;
    observed_quantity: string;
    accepted_quantity: string | null;
    reason: string;
    notes: string | null;
    status: string;
    resolution_type: string | null;
    resolution_reason: string | null;
    reported_at: string;
    resolved_at: string | null;
    reporter: { id: string; name: string } | null;
    resolver: { id: string; name: string } | null;
}

interface GoodsReceipt {
    id: string;
    receipt_number: string;
    status: string;
    received_at: string | null;
    posted_at: string | null;
    void_reason: string | null;
    voided_at: string | null;
    purchase_order: {
        id: string;
        vendor: { id: string; name: string } | null;
        purchase_request: { request_no: string } | null;
    } | null;
    lines: {
        id: string;
        received_quantity: number;
        posting_disposition: 'ACCEPTED' | 'REJECTED';
        inventory_item: { id: string; name: string; sku: string | null } | null;
        inventory_location: { id: string; name: string } | null;
        inventory_unit: { id: string; name: string; code: string } | null;
        purchase_order_line: {
            id: string;
            ordered_quantity: number;
            received_quantity: number;
            remaining_quantity: number;
        } | null;
        stock_movement: { id: string; quantity: number; occurred_at: string; movement_type: { value: string } } | null;
        exceptions: ReceiptException[];
    }[];
    exceptions: ReceiptException[];
    received_by: { id: string; name: string } | null;
    created_by: { id: string; name: string } | null;
    voided_by: { id: string; name: string } | null;
    created_at: string;
}

interface Props {
    receipt: GoodsReceipt;
    capabilities: { canReport: boolean; canResolve: boolean };
    exceptionOptions: {
        discrepancyTypes: string[];
        inspectionResults: string[];
        resolutionTypes: string[];
    };
}

export default function GoodsReceiptDetail({ receipt, capabilities, exceptionOptions }: Props) {
    const confirmForm = useForm({});
    const postForm = useForm({});
    const [reportLineId, setReportLineId] = useState<string | null>(null);
    const [resolveExceptionId, setResolveExceptionId] = useState<string | null>(null);
    const exceptionForm = useForm({
        goods_receipt_line_id: '', discrepancy_type: '', inspection_result: '', observed_quantity: '',
        reason: '', notes: '', idempotency_key: '',
    });
    const resolutionForm = useForm({ resolution_type: 'ACCEPT_AS_IS', accepted_quantity: '', reason: '' });
    const voidForm = useForm({ reason: '' });

    const isDraft = receipt.status === 'DRAFT';
    const isConfirmationPending = receipt.status === 'CONFIRMATION_PENDING';
    const isPosted = receipt.status === 'POSTED';
    const isVoided = receipt.status === 'VOIDED';
    const openExceptions = receipt.exceptions.filter(exception => exception.status === 'OPEN');
    const acceptedLines = receipt.lines.filter(line => line.posting_disposition !== 'REJECTED');

    const handleConfirm = () => {
        confirmForm.post(`/operations/inventory/goods-receipts/${receipt.id}/confirm`);
    };

    const handlePost = () => {
        postForm.post(`/operations/inventory/goods-receipts/${receipt.id}/post`);
    };

    const openReport = (line: GoodsReceipt['lines'][number]) => {
        setReportLineId(line.id);
        exceptionForm.setData({
            goods_receipt_line_id: line.id,
            discrepancy_type: '',
            inspection_result: '',
            observed_quantity: String(line.received_quantity),
            reason: '',
            notes: '',
            idempotency_key: `gr-ex-${receipt.id}-${line.id}-${Date.now()}`,
        });
    };

    const submitException = () => {
        exceptionForm.post(`/operations/inventory/goods-receipts/${receipt.id}/exceptions`, {
            preserveScroll: true,
            onSuccess: () => setReportLineId(null),
        });
    };

    const submitResolution = () => {
        if (!resolveExceptionId || !window.confirm('Apply this controlled exception resolution?')) return;
        resolutionForm.post(`/operations/inventory/goods-receipt-exceptions/${resolveExceptionId}/resolve`, {
            preserveScroll: true,
            onSuccess: () => setResolveExceptionId(null),
        });
    };

    const submitVoid = () => {
        if (!voidForm.data.reason.trim() || !window.confirm('Void this non-posted receipt? This is terminal.')) return;
        voidForm.post(`/operations/inventory/goods-receipts/${receipt.id}/void`, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={`GRN ${receipt.receipt_number}`} />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">{receipt.receipt_number}</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        PO: {receipt.purchase_order?.id?.slice(-8) ?? '—'} | Vendor: {receipt.purchase_order?.vendor?.name ?? '—'}
                    </p>
                </div>
                <Link href="/operations/inventory/goods-receipts" className="text-sm text-blue-600 hover:text-blue-800">
                    &larr; Back to Receipts
                </Link>
            </div>

            {/* Status */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <div className="flex items-center gap-4">
                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                        isPosted ? 'bg-green-100 text-green-700' :
                        isVoided ? 'bg-red-100 text-red-700' :
                        isConfirmationPending ? 'bg-yellow-100 text-yellow-700' :
                        'bg-gray-100 text-gray-700'
                    }`}>
                        {receipt.status}
                    </span>
                    {isDraft && (
                        <button onClick={handleConfirm} disabled={confirmForm.processing || openExceptions.length > 0 || acceptedLines.length === 0}
                            className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 disabled:opacity-50">
                            Confirm Readiness
                        </button>
                    )}
                    {isConfirmationPending && (
                        <>
                            <Link href="/system/sensitive-action-confirmation"
                                className="bg-amber-600 text-white px-4 py-2 rounded text-sm hover:bg-amber-700">
                                Confirm Sensitive Action
                            </Link>
                            <button onClick={handlePost} disabled={postForm.processing}
                                className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700 disabled:opacity-50">
                                Post Receipt
                            </button>
                        </>
                    )}
                </div>
                {receipt.received_at && (
                    <p className="text-xs text-gray-500 mt-2">
                        Received: {new Date(receipt.received_at).toLocaleString()}
                    </p>
                )}
                {isDraft && (openExceptions.length > 0 || acceptedLines.length === 0) && (
                    <p className="text-sm text-amber-700 mt-3">
                        Confirmation is blocked until all exceptions are resolved and at least one line is accepted.
                    </p>
                )}
                {isVoided && (
                    <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        <strong>Voided:</strong> {receipt.void_reason} · {receipt.voided_by?.name ?? '—'} ·{' '}
                        {receipt.voided_at ? new Date(receipt.voided_at).toLocaleString() : '—'}
                    </div>
                )}
            </div>

            {(isDraft || isConfirmationPending) && capabilities.canResolve && (
                <div className="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 className="text-sm font-semibold text-gray-800 mb-3">Controlled Receipt Void</h2>
                    <div className="flex gap-3">
                        <input value={voidForm.data.reason} onChange={event => voidForm.setData('reason', event.target.value)}
                            placeholder="Required source-backed void reason" className="flex-1 rounded border-gray-300 text-sm" />
                        <button onClick={submitVoid} disabled={voidForm.processing}
                            className="rounded bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                            Void Receipt
                        </button>
                    </div>
                </div>
            )}

            {/* Lines */}
            <div className="bg-white rounded-lg shadow overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-700">Receipt Lines</h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Qty Received</th>
                                <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Ordered</th>
                                <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Remaining</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Ledger</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Disposition</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {receipt.lines.map(line => (
                                <tr key={line.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-3 font-medium text-gray-900">
                                        {line.inventory_item?.name ?? line.id}
                                    </td>
                                    <td className="px-6 py-3 text-gray-600">
                                        {line.inventory_location?.name ?? line.inventory_location?.id ?? '—'}
                                    </td>
                                    <td className="px-6 py-3 text-right font-mono text-gray-700">
                                        {line.received_quantity}
                                    </td>
                                    <td className="px-6 py-3 text-right text-gray-600">
                                        {line.purchase_order_line?.ordered_quantity ?? '—'}
                                    </td>
                                    <td className="px-6 py-3 text-right text-gray-600">
                                        {line.purchase_order_line?.remaining_quantity ?? '—'}
                                    </td>
                                    <td className="px-6 py-3">
                                        {line.stock_movement ? (
                                            <span className="text-xs text-green-600 font-medium">
                                                Stock Movement: {line.stock_movement.id.slice(-8)}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-3">
                                        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${line.posting_disposition === 'REJECTED' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'}`}>
                                            {line.posting_disposition}
                                        </span>
                                    </td>
                                    <td className="px-6 py-3">
                                        {isDraft && capabilities.canReport && (
                                            <button onClick={() => openReport(line)} className="text-xs font-medium text-blue-700 hover:underline">
                                                Report Exception / Inspection
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {reportLineId && (
                <div className="fixed inset-0 z-40 flex justify-end bg-black/30">
                    <div className="h-full w-full max-w-lg overflow-y-auto bg-white p-6 shadow-xl">
                        <div className="flex items-center justify-between mb-5">
                            <h2 className="text-lg font-semibold">Report Controlled Receipt Evidence</h2>
                            <button onClick={() => setReportLineId(null)} className="text-gray-500">Close</button>
                        </div>
                        <div className="space-y-4">
                            <label className="block text-sm">Discrepancy type
                                <select value={exceptionForm.data.discrepancy_type} onChange={event => exceptionForm.setData('discrepancy_type', event.target.value)} className="mt-1 w-full rounded border-gray-300">
                                    <option value="">Inspection only</option>
                                    {exceptionOptions.discrepancyTypes.map(value => <option key={value}>{value}</option>)}
                                </select>
                            </label>
                            <label className="block text-sm">Inspection result
                                <select value={exceptionForm.data.inspection_result} onChange={event => exceptionForm.setData('inspection_result', event.target.value)} className="mt-1 w-full rounded border-gray-300">
                                    <option value="">Not recorded</option>
                                    {exceptionOptions.inspectionResults.map(value => <option key={value}>{value}</option>)}
                                </select>
                            </label>
                            <label className="block text-sm">Observed quantity
                                <input type="number" step="0.001" value={exceptionForm.data.observed_quantity} onChange={event => exceptionForm.setData('observed_quantity', event.target.value)} className="mt-1 w-full rounded border-gray-300" />
                            </label>
                            <label className="block text-sm">Reason
                                <textarea value={exceptionForm.data.reason} onChange={event => exceptionForm.setData('reason', event.target.value)} className="mt-1 w-full rounded border-gray-300" />
                            </label>
                            <label className="block text-sm">Notes
                                <textarea value={exceptionForm.data.notes} onChange={event => exceptionForm.setData('notes', event.target.value)} className="mt-1 w-full rounded border-gray-300" />
                            </label>
                            <button onClick={submitException} disabled={exceptionForm.processing}
                                className="w-full rounded bg-blue-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                Record Immutable Evidence
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <div className="bg-white rounded-lg shadow p-6 mt-6">
                <h2 className="text-sm font-semibold text-gray-800 mb-4">Exception & Inspection Provenance</h2>
                {receipt.exceptions.length === 0 ? <p className="text-sm text-gray-500">No exception or inspection evidence.</p> : (
                    <div className="space-y-4">
                        {receipt.exceptions.map(exception => (
                            <div key={exception.id} className="rounded border border-gray-200 p-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="font-medium text-gray-900">{exception.discrepancy_type ?? 'INSPECTION'} · {exception.inspection_result ?? '—'}</p>
                                        <p className="text-sm text-gray-600 mt-1">Observed {exception.observed_quantity}; accepted {exception.accepted_quantity ?? 'pending'} · {exception.reason}</p>
                                        <p className="text-xs text-gray-500 mt-2">Reported by {exception.reporter?.name ?? '—'} at {new Date(exception.reported_at).toLocaleString()}</p>
                                        {exception.resolved_at && <p className="text-xs text-gray-500">Resolved {exception.resolution_type} by {exception.resolver?.name ?? '—'} at {new Date(exception.resolved_at).toLocaleString()}</p>}
                                    </div>
                                    <span className={`rounded-full px-2 py-1 text-xs font-semibold ${exception.status === 'OPEN' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>{exception.status}</span>
                                </div>
                                {exception.status === 'OPEN' && isDraft && capabilities.canResolve && (
                                    <button onClick={() => setResolveExceptionId(exception.id)} className="mt-3 text-sm font-medium text-blue-700 hover:underline">Resolve Exception</button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {resolveExceptionId && (
                <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-semibold mb-4">Resolve Controlled Exception</h2>
                        <div className="space-y-4">
                            <select value={resolutionForm.data.resolution_type} onChange={event => resolutionForm.setData('resolution_type', event.target.value)} className="w-full rounded border-gray-300">
                                {exceptionOptions.resolutionTypes.map(value => <option key={value}>{value}</option>)}
                            </select>
                            {resolutionForm.data.resolution_type === 'ADJUST_ACCEPTED_QUANTITY' && (
                                <input type="number" step="0.001" value={resolutionForm.data.accepted_quantity} onChange={event => resolutionForm.setData('accepted_quantity', event.target.value)} placeholder="Reduced accepted quantity" className="w-full rounded border-gray-300" />
                            )}
                            <textarea value={resolutionForm.data.reason} onChange={event => resolutionForm.setData('reason', event.target.value)} placeholder="Independent resolution reason" className="w-full rounded border-gray-300" />
                            <div className="flex justify-end gap-3">
                                <button onClick={() => setResolveExceptionId(null)} className="rounded px-4 py-2 text-sm text-gray-600">Cancel</button>
                                <button onClick={submitResolution} disabled={resolutionForm.processing} className="rounded bg-blue-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Resolve</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Metadata */}
            <div className="bg-white rounded-lg shadow p-6 mt-6">
                <h3 className="text-sm font-semibold text-gray-700 mb-3">Receipt Details</h3>
                <div className="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span className="text-gray-500">Created By:</span>{' '}
                        <span className="text-gray-800">{receipt.created_by?.name ?? '—'}</span>
                    </div>
                    <div>
                        <span className="text-gray-500">Receiver:</span>{' '}
                        <span className="text-gray-800">{receipt.received_by?.name ?? '—'}</span>
                    </div>
                    <div>
                        <span className="text-gray-500">Created:</span>{' '}
                        <span className="text-gray-800">{new Date(receipt.created_at).toLocaleString()}</span>
                    </div>
                    <div>
                        <span className="text-gray-500">Posted:</span>{' '}
                        <span className="text-gray-800">{receipt.posted_at ? new Date(receipt.posted_at).toLocaleString() : '—'}</span>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
