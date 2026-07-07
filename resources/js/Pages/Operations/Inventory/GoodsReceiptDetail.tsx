import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';

interface GoodsReceipt {
    id: string;
    receipt_number: string;
    status: string;
    received_at: string | null;
    posted_at: string | null;
    purchase_order: {
        id: string;
        vendor: { id: string; name: string } | null;
        purchase_request: { request_no: string } | null;
    } | null;
    lines: {
        id: string;
        received_quantity: number;
        inventory_item: { id: string; name: string; sku: string | null } | null;
        inventory_location: { id: string; name: string } | null;
        inventory_unit: { id: string; name: string; abbreviation: string | null } | null;
        purchase_order_line: {
            id: string;
            ordered_quantity: number;
            received_quantity: number;
            remaining_quantity: number;
        } | null;
        stock_movement: { id: string; quantity: number; occurred_at: string; movement_type: { value: string } } | null;
    }[];
    received_by: { id: string; name: string } | null;
    created_by: { id: string; name: string } | null;
    created_at: string;
}

interface Props {
    receipt: GoodsReceipt;
}

export default function GoodsReceiptDetail({ receipt }: Props) {
    const confirmForm = useForm({});
    const postForm = useForm({});

    const isDraft = receipt.status === 'DRAFT';
    const isConfirmationPending = receipt.status === 'CONFIRMATION_PENDING';
    const isPosted = receipt.status === 'POSTED';

    const handleConfirm = () => {
        confirmForm.post(`/operations/inventory/goods-receipts/${receipt.id}/confirm`);
    };

    const handlePost = () => {
        postForm.post(`/operations/inventory/goods-receipts/${receipt.id}/post`);
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
                        isConfirmationPending ? 'bg-yellow-100 text-yellow-700' :
                        'bg-gray-100 text-gray-700'
                    }`}>
                        {receipt.status}
                    </span>
                    {isDraft && (
                        <button onClick={handleConfirm} disabled={confirmForm.processing}
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
            </div>

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
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

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
