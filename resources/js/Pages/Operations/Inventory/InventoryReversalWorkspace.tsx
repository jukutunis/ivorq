import IvorqLayout from '@/Layouts/IvorqLayout';
import { useForm, Link } from '@inertiajs/react';
import React, { useState } from 'react';
import StatusBadge from '@/Components/Ivorq/primitives/StatusBadge';
import Button from '@/Components/Ivorq/primitives/Button';
import Icon from '@/Components/Ivorq/primitives/Icon';

interface InventoryTransaction {
    id: string;
    transaction_type: string;
    quantity_before: string;
    quantity_change: string;
    quantity_after: string;
    unit_cost: string | null;
    total_cost: string | null;
    business_date: string;
    occurred_at: string;
    currency_code: string;
    valuation_sequence: number | null;
}

interface ApprovalRequest {
    id: string;
    status: string;
    notes: {
        reversal_reason?: string;
        request_idempotency_key?: string;
    } | null;
}

interface Props {
    transaction: InventoryTransaction;
    isEligible: boolean;
    blocker: string | null;
    idempotencyKey: string | null;
    existingApproval: ApprovalRequest | null;
    existingReversal: InventoryTransaction | null;
}

export default function InventoryReversalWorkspace({
    transaction,
    isEligible,
    blocker,
    idempotencyKey,
    existingApproval,
    existingReversal,
}: Props) {
    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        original_inventory_transaction_id: transaction.id,
        reversal_reason: '',
        request_idempotency_key: idempotencyKey || '',
    });

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('operations.inventory.reversals.request'), {
            onSuccess: () => {
                setShowForm(false);
                reset('reversal_reason');
            },
        });
    };

    return (
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            {/* Header and Breadcrumbs */}
            <nav className="flex mb-4 text-sm text-gray-500" aria-label="Breadcrumb">
                <ol className="inline-flex items-center space-x-1 md:space-x-3">
                    <li>
                        <span className="hover:text-gray-700">Operations</span>
                    </li>
                    <li>
                        <span className="mx-2">/</span>
                        <span className="hover:text-gray-700">Inventory</span>
                    </li>
                    <li>
                        <span className="mx-2">/</span>
                        <span className="hover:text-gray-700">Transactions</span>
                    </li>
                    <li>
                        <span className="mx-2">/</span>
                        <span className="text-gray-900 font-medium">{transaction.id}</span>
                    </li>
                </ol>
            </nav>

            <div className="flex justify-between items-center border-b border-gray-200 pb-5 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Inventory Transaction Detail</h1>
                    <p className="mt-1 text-sm text-gray-500">Controlled Inventory Transaction Reversal Workspace</p>
                </div>
            </div>

            {/* Context Indicators Strip */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white p-4 rounded border border-gray-200 shadow-sm">
                    <span className="text-xs font-semibold uppercase text-gray-400 block mb-1">Transaction Ref</span>
                    <span className="text-sm font-bold text-gray-800 break-all">{transaction.id}</span>
                </div>
                <div className="bg-white p-4 rounded border border-gray-200 shadow-sm">
                    <span className="text-xs font-semibold uppercase text-gray-400 block mb-1">Request Eligibility</span>
                    <span className={`text-sm font-bold ${isEligible ? 'text-green-600' : 'text-red-600'}`}>
                        {isEligible ? 'Eligible' : 'Blocked'}
                    </span>
                </div>
                <div className="bg-white p-4 rounded border border-gray-200 shadow-sm">
                    <span className="text-xs font-semibold uppercase text-gray-400 block mb-1">Approval State</span>
                    <span className="text-sm font-bold text-gray-800">
                        {existingApproval ? existingApproval.status : 'None'}
                    </span>
                </div>
                <div className="bg-white p-4 rounded border border-gray-200 shadow-sm">
                    <span className="text-xs font-semibold uppercase text-gray-400 block mb-1">Controlled Actions</span>
                    <span className="text-sm font-bold text-gray-800">
                        {isEligible ? 'Available' : 'Unavailable'}
                    </span>
                </div>
            </div>

            {/* Main two-column workspace */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Left primary column: Immutable transaction evidence */}
                <div className="lg:col-span-2 space-y-6">
                    <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
                            <h2 className="text-lg font-bold text-gray-900">Immutable Original Evidence</h2>
                            <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                Original Immutable
                            </span>
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                            <div>
                                <span className="text-xs text-gray-400 block">Transaction Type</span>
                                <span className="text-sm font-semibold text-gray-800 capitalize">
                                    {transaction.transaction_type.replace('_', ' ')}
                                </span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Valuation Sequence</span>
                                <span className="text-sm font-semibold text-gray-800">
                                    {transaction.valuation_sequence ?? 'N/A'}
                                </span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Business Date</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.business_date}</span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Occurred At</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.occurred_at}</span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Quantity Before</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.quantity_before}</span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Quantity Change</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.quantity_change}</span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Quantity After</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.quantity_after}</span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Currency</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.currency_code}</span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Unit Cost</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.unit_cost ?? 'N/A'}</span>
                            </div>
                            <div>
                                <span className="text-xs text-gray-400 block">Total Cost</span>
                                <span className="text-sm font-semibold text-gray-800">{transaction.total_cost ?? 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Right persistent action column */}
                <div className="space-y-6">
                    {/* Eligibility & Guidance Panel */}
                    <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                        <h3 className="text-md font-bold text-gray-900 mb-3">Reversal Eligibility</h3>
                        {isEligible ? (
                            <div className="space-y-3">
                                <div className="p-3 bg-green-50 text-green-800 rounded text-sm">
                                    This transaction can be submitted for approval. A final approved request is required before controlled reversal can occur.
                                </div>
                                <div className="text-xs text-gray-500">
                                    Next step: Click "Request Reversal" to fill in the reason and submit to approval workflow.
                                </div>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <div className="p-3 bg-red-50 text-red-800 rounded text-sm">
                                    <strong>Blocked:</strong> {blocker || 'This transaction cannot be reversed.'}
                                </div>
                                <div className="text-xs text-gray-500">
                                     rechecking or corrections cannot bypass active blocker constraints.
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Context Actions Panel */}
                    <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                        <h3 className="text-md font-bold text-gray-900 mb-3">Context Actions</h3>
                        <div className="space-y-2">
                            {isEligible && !showForm && (
                                <button
                                    onClick={() => setShowForm(true)}
                                    className="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded transition-colors"
                                >
                                    Request Reversal
                                </button>
                            )}
                            <button disabled className="w-full bg-gray-100 text-gray-400 text-sm font-medium py-2 px-4 rounded cursor-not-allowed">
                                View Source Document
                            </button>
                            <button disabled className="w-full bg-gray-100 text-gray-400 text-sm font-medium py-2 px-4 rounded cursor-not-allowed">
                                View Cost Evidence
                            </button>
                            {existingApproval && (
                                <div className="mt-4 p-3 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
                                    Linked Approval ID: <strong>{existingApproval.id}</strong>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Request Drawer / Form Panel */}
                    {showForm && isEligible && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6 border-l-4 border-l-blue-500">
                            <h3 className="text-md font-bold text-gray-900 mb-2">Request Controlled Reversal</h3>
                            <p className="text-xs text-gray-500 mb-4">
                                Submit approval request for original transaction. Note that all quantities, costs, and valuation sequence allocations are server-controlled and immutable.
                            </p>
                            <form onSubmit={onSubmit} className="space-y-4">
                                <div>
                                    <label htmlFor="reversal_reason" className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                                        Reversal Reason
                                    </label>
                                    <textarea
                                        id="reversal_reason"
                                        rows={3}
                                        value={data.reversal_reason}
                                        onChange={(e) => setData('reversal_reason', e.target.value)}
                                        className="w-full p-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                                        placeholder="Explain why this reversal is necessary..."
                                        required
                                    />
                                    {errors.reversal_reason && (
                                        <span className="text-xs text-red-500 mt-1 block">{errors.reversal_reason}</span>
                                    )}
                                </div>
                                <input type="hidden" name="request_idempotency_key" value={data.request_idempotency_key} />
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setShowForm(false);
                                            reset('reversal_reason');
                                        }}
                                        className="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium py-2 rounded transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 rounded transition-colors disabled:opacity-50"
                                    >
                                        {processing ? 'Submitting...' : 'Submit Request'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

InventoryReversalWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
