import IvorqLayout from '@/Layouts/IvorqLayout';
import { useForm, Link } from '@inertiajs/react';
import React, { useState } from 'react';

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
    reverses_inventory_transaction_id: string | null;
}

interface ApprovalRequest {
    id: string;
    status: string;
    notes: {
        reversal_reason?: string;
        request_idempotency_key?: string;
    } | null;
    requested_at: string | null;
    completed_at: string | null;
}

interface AuditLog {
    id: string;
    event: string;
    created_at: string;
}

interface Props {
    transaction: InventoryTransaction;
    isEligible: boolean;
    blocker: string | null;
    idempotencyKey: string | null;
    existingApproval: ApprovalRequest | null;
    existingReversal: InventoryTransaction | null;
    requesterName: string | null;
    approverName: string | null;
    approvedAt: string | null;
    workflowLabel: string | null;
    isExecutionAvailable: boolean;
    executionIdempotencyKey: string | null;
    auditLog: AuditLog | null;
    executorName: string | null;
}

export default function InventoryReversalWorkspace({
    transaction,
    isEligible,
    blocker,
    idempotencyKey,
    existingApproval,
    existingReversal,
    requesterName,
    approverName,
    approvedAt,
    workflowLabel,
    isExecutionAvailable,
    executionIdempotencyKey,
    auditLog,
    executorName,
}: Props) {
    const [showRequestForm, setShowRequestForm] = useState(false);
    const [showExecuteForm, setShowExecuteForm] = useState(false);

    const requestForm = useForm({
        original_inventory_transaction_id: transaction.id,
        reversal_reason: '',
        request_idempotency_key: idempotencyKey || '',
    });

    const executeForm = useForm({
        execution_idempotency_key: executionIdempotencyKey || '',
    });

    const onRequestSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        requestForm.post(route('operations.inventory.reversals.request'), {
            onSuccess: () => {
                setShowRequestForm(false);
                requestForm.reset('reversal_reason');
            },
        });
    };

    const onExecuteSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!existingApproval) return;
        executeForm.post(route('operations.inventory.reversals.execute', { approvalRequest: existingApproval.id }), {
            onSuccess: () => {
                setShowExecuteForm(false);
            },
        });
    };

    // State 3 check: does the current page represent a transaction that has already been reversed, or is this the reversal itself?
    const hasReversal = !!existingReversal;
    const isReversal = !!transaction.reverses_inventory_transaction_id;
    const isState3 = hasReversal || isReversal;

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
                    <h1 className="text-2xl font-bold text-gray-900">
                        {isReversal ? 'Inventory Reversal Transaction' : 'Inventory Transaction Detail'}
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        {isState3 ? 'Reversal Executed — Evidence Available' : 'Controlled Inventory Transaction Reversal Workspace'}
                    </p>
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
                    <span className={`text-sm font-bold ${isState3 ? 'text-red-600' : (isEligible ? 'text-green-600' : 'text-red-600')}`}>
                        {isState3 ? 'Blocked (Executed)' : (isEligible ? 'Eligible' : 'Blocked')}
                    </span>
                </div>
                <div className="bg-white p-4 rounded border border-gray-200 shadow-sm">
                    <span className="text-xs font-semibold uppercase text-gray-400 block mb-1">Approval State</span>
                    <span className="text-sm font-bold text-gray-800">
                        {isReversal ? 'Approved & Posted' : (existingApproval ? existingApproval.status : 'None')}
                    </span>
                </div>
                <div className="bg-white p-4 rounded border border-gray-200 shadow-sm">
                    <span className="text-xs font-semibold uppercase text-gray-400 block mb-1">Controlled Actions</span>
                    <span className="text-sm font-bold text-gray-800">
                        {isState3 ? 'Executed' : (isExecutionAvailable ? 'Execute Available' : (isEligible ? 'Request Available' : 'Unavailable'))}
                    </span>
                </div>
            </div>

            {/* Main two-column workspace */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Left primary column: Immutable original transaction and reversal results */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Original Transaction Evidence */}
                    <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
                            <h2 className="text-lg font-bold text-gray-900">
                                {isReversal ? 'Reversal Transaction Evidence' : 'Immutable Original Evidence'}
                            </h2>
                            <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                {isReversal ? 'Reversal Record' : 'Original transaction remains unchanged'}
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

                    {/* State 3: Controlled Reversal Result Panel */}
                    {existingReversal && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6 border-l-4 border-l-green-600">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
                                <h2 className="text-lg font-bold text-gray-900">Controlled Reversal Result</h2>
                                <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                    Reversal Posted
                                </span>
                            </div>
                            <p className="text-xs text-gray-500 mb-4">
                                A new linked reversal transaction has been recorded from immutable original transaction and approval evidence.
                            </p>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                                <div>
                                    <span className="text-xs text-gray-400 block">Reversal Transaction Ref</span>
                                    <span className="text-sm font-semibold text-gray-800 break-all">{existingReversal.id}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Reversal Type</span>
                                    <span className="text-sm font-semibold text-gray-800 capitalize">
                                        {existingReversal.transaction_type.replace('_', ' ')}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Posting Status</span>
                                    <span className="text-sm font-semibold text-gray-800">Posted</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Execution Timestamp</span>
                                    <span className="text-sm font-semibold text-gray-800">{existingReversal.occurred_at}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Executor Identity</span>
                                    <span className="text-sm font-semibold text-gray-800">{executorName ?? 'N/A'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Execution Business Date</span>
                                    <span className="text-sm font-semibold text-gray-800">{existingReversal.business_date}</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* State 3: Recorded Operational Impact Panel */}
                    {existingReversal && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                            <h2 className="text-lg font-bold text-gray-900 border-b border-gray-100 pb-4 mb-4">
                                Recorded Operational Impact
                            </h2>
                            <div className="space-y-4">
                                <div className="p-3 bg-gray-50 rounded">
                                    <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Physical Quantity</h4>
                                    <div className="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <span className="text-xs text-gray-400 block">Original Transaction Quantity</span>
                                            <span className="font-semibold text-gray-800">{transaction.quantity_change}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-gray-400 block">Linked Reversal Quantity</span>
                                            <span className="font-semibold text-gray-800">{existingReversal.quantity_change}</span>
                                        </div>
                                    </div>
                                    <div className="mt-2 text-xs text-gray-600">
                                        Recorded operational outcome: Reversal posting applied exactly the inverse of the original stock delta.
                                    </div>
                                </div>

                                <div className="p-3 bg-gray-50 rounded">
                                    <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Carrying Value</h4>
                                    <div className="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <span className="text-xs text-gray-400 block">Original Carrying Value</span>
                                            <span className="font-semibold text-gray-800">
                                                {transaction.currency_code} {transaction.total_cost ?? '0.00'}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-gray-400 block">Linked Reversal Carrying Value</span>
                                            <span className="font-semibold text-gray-800">
                                                {existingReversal.currency_code} {existingReversal.total_cost ?? '0.00'}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="mt-2 text-xs text-gray-600">
                                        Recorded cost outcome: Carrying value valuation was offset by the exact negated original total cost.
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Approval & Execution Evidence Panel */}
                    {existingApproval && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
                                <h2 className="text-lg font-bold text-gray-900">Approval & Execution Evidence</h2>
                                <span className="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                    Workflow Evidence
                                </span>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                                <div>
                                    <span className="text-xs text-gray-400 block">Approval Reference</span>
                                    <span className="text-sm font-semibold text-gray-800 break-all">{existingApproval.id}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Workflow Route</span>
                                    <span className="text-sm font-semibold text-gray-800">{workflowLabel ?? 'N/A'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Requested By</span>
                                    <span className="text-sm font-semibold text-gray-800">{requesterName ?? 'N/A'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Requested At</span>
                                    <span className="text-sm font-semibold text-gray-800">{existingApproval.requested_at ?? 'N/A'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Reversal Reason</span>
                                    <span className="text-sm font-semibold text-gray-800">
                                        {existingApproval.notes?.reversal_reason ?? 'N/A'}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-400 block">Approval Status</span>
                                    <span className="text-sm font-bold text-gray-800">{existingApproval.status}</span>
                                </div>
                                {approverName && (
                                    <>
                                        <div>
                                            <span className="text-xs text-gray-400 block">Approved By</span>
                                            <span className="text-sm font-semibold text-gray-800">{approverName}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-gray-400 block">Approved At</span>
                                            <span className="text-sm font-semibold text-gray-800">{approvedAt ?? 'N/A'}</span>
                                        </div>
                                    </>
                                )}
                                {existingReversal && (
                                    <>
                                        <div className="border-t border-gray-100 pt-3 md:col-span-2">
                                            <strong className="text-xs text-gray-400 block mb-2 uppercase">Execution Details</strong>
                                        </div>
                                        <div>
                                            <span className="text-xs text-gray-400 block">Executor Identity</span>
                                            <span className="text-sm font-semibold text-gray-800">{executorName ?? 'N/A'}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-gray-400 block">Executed At</span>
                                            <span className="text-sm font-semibold text-gray-800">{existingReversal.occurred_at}</span>
                                        </div>
                                        {auditLog && (
                                            <div>
                                                <span className="text-xs text-gray-400 block">Audit Log Reference</span>
                                                <span className="text-sm font-semibold text-gray-800 break-all">{auditLog.id}</span>
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Evidence Chronology */}
                    {isState3 && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                            <h2 className="text-lg font-bold text-gray-900 border-b border-gray-100 pb-4 mb-4">Evidence Chronology</h2>
                            <div className="flow-root">
                                <ul className="-mb-8">
                                    <li>
                                        <div className="relative pb-8">
                                            <span className="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true" />
                                            <div className="relative flex space-x-3">
                                                <div>
                                                    <span className="h-8 w-8 rounded-full bg-gray-100 flex items-center justify-center text-gray-500">
                                                        ✓
                                                    </span>
                                                </div>
                                                <div className="flex-1 min-w-0 pt-1.5 flex justify-between space-x-4">
                                                    <div>
                                                        <p className="text-sm text-gray-800">Original transaction recorded</p>
                                                    </div>
                                                    <div className="text-right text-xs text-gray-400 whitespace-nowrap">
                                                        {transaction.occurred_at}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                    {existingApproval && (
                                        <li>
                                            <div className="relative pb-8">
                                                <span className="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true" />
                                                <div className="relative flex space-x-3">
                                                    <div>
                                                        <span className="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                            ✓
                                                        </span>
                                                    </div>
                                                    <div className="flex-1 min-w-0 pt-1.5 flex justify-between space-x-4">
                                                        <div>
                                                            <p className="text-sm text-gray-800">
                                                                Reversal request submitted by <span className="font-semibold">{requesterName ?? 'requester'}</span>
                                                            </p>
                                                        </div>
                                                        <div className="text-right text-xs text-gray-400 whitespace-nowrap">
                                                            {existingApproval.requested_at}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    )}
                                    {existingApproval && approverName && (
                                        <li>
                                            <div className="relative pb-8">
                                                <span className="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true" />
                                                <div className="relative flex space-x-3">
                                                    <div>
                                                        <span className="h-8 w-8 rounded-full bg-blue-500 flex items-center justify-center text-white text-xs">
                                                            ✓
                                                        </span>
                                                    </div>
                                                    <div className="flex-1 min-w-0 pt-1.5 flex justify-between space-x-4">
                                                        <div>
                                                            <p className="text-sm text-gray-800">
                                                                Final approval recorded by <span className="font-semibold">{approverName}</span>
                                                            </p>
                                                        </div>
                                                        <div className="text-right text-xs text-gray-400 whitespace-nowrap">
                                                            {approvedAt}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    )}
                                    {existingReversal && (
                                        <li>
                                            <div className="relative pb-8">
                                                <div className="relative flex space-x-3">
                                                    <div>
                                                        <span className="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center text-white text-xs">
                                                            ✓
                                                        </span>
                                                    </div>
                                                    <div className="flex-1 min-w-0 pt-1.5 flex justify-between space-x-4">
                                                        <div>
                                                            <p className="text-sm text-gray-800">
                                                                Controlled reversal executed by <span className="font-semibold">{executorName ?? 'executor'}</span>
                                                            </p>
                                                        </div>
                                                        <div className="text-right text-xs text-gray-400 whitespace-nowrap">
                                                            {existingReversal.occurred_at}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    )}
                                </ul>
                            </div>
                        </div>
                    )}
                </div>

                {/* Right persistent action column */}
                <div className="space-y-6">
                    {/* Eligibility & Guidance Panel */}
                    <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                        <h3 className="text-md font-bold text-gray-900 mb-3">Controlled Execution</h3>
                        {isState3 ? (
                            <div className="space-y-3">
                                <div className="p-3 bg-green-50 text-green-800 rounded text-sm">
                                    Reversal Executed — Evidence Available
                                </div>
                                <div className="text-xs text-gray-500">
                                    A linked reversal transaction has been recorded. The original transaction remains unchanged. A second reversal request or execution is unavailable.
                                </div>
                            </div>
                        ) : isExecutionAvailable ? (
                            <div className="space-y-3">
                                <div className="p-3 bg-blue-50 text-blue-800 rounded text-sm">
                                    Final approval is recorded. Controlled execution will be revalidated by the backend when submitted.
                                </div>
                                <div className="text-xs text-gray-500">
                                    Next step: Click "Review and Execute Reversal" to perform controlled confirmation.
                                </div>
                            </div>
                        ) : isEligible ? (
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
                                    Controlled checks cannot bypass active blocker constraints.
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Context Actions Panel */}
                    <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                        <h3 className="text-md font-bold text-gray-900 mb-3">Context Actions</h3>
                        <div className="space-y-2">
                            {isState3 ? (
                                <div className="p-3 bg-gray-50 border border-gray-200 rounded text-xs text-gray-600 mb-2">
                                    <strong>Reversal already executed</strong>
                                    <span className="block mt-1">
                                        This original transaction has a linked reversal transaction. A second reversal request or controlled execution is unavailable.
                                    </span>
                                </div>
                            ) : (
                                <>
                                    {isEligible && !showRequestForm && (
                                        <button
                                            onClick={() => setShowRequestForm(true)}
                                            className="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded transition-colors"
                                        >
                                            Request Reversal
                                        </button>
                                    )}
                                    {isExecutionAvailable && !showExecuteForm && (
                                        <button
                                            onClick={() => setShowExecuteForm(true)}
                                            className="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded transition-colors"
                                        >
                                            Review and Execute Reversal
                                        </button>
                                    )}
                                </>
                            )}
                            <button disabled className="w-full bg-gray-100 text-gray-400 text-sm font-medium py-2 px-4 rounded cursor-not-allowed">
                                View Source Document
                            </button>
                            <button disabled className="w-full bg-gray-100 text-gray-400 text-sm font-medium py-2 px-4 rounded cursor-not-allowed">
                                View Cost Evidence
                            </button>
                        </div>
                    </div>

                    {/* Related Records Panel */}
                    {isState3 && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6">
                            <h3 className="text-md font-bold text-gray-900 mb-3">Related Records</h3>
                            <div className="space-y-2 text-sm">
                                {isReversal ? (
                                    <div>
                                        <span className="text-xs text-gray-400 block">Original Transaction</span>
                                        <Link
                                            href={route('operations.inventory.reversals.show', { transaction: transaction.reverses_inventory_transaction_id })}
                                            className="text-blue-600 hover:underline break-all"
                                        >
                                            {transaction.reverses_inventory_transaction_id}
                                        </Link>
                                    </div>
                                ) : (
                                    existingReversal && (
                                        <div>
                                            <span className="text-xs text-gray-400 block">Linked Reversal Transaction</span>
                                            <Link
                                                href={route('operations.inventory.reversals.show', { transaction: existingReversal.id })}
                                                className="text-blue-600 hover:underline break-all"
                                            >
                                                {existingReversal.id}
                                            </Link>
                                        </div>
                                    )
                                )}
                                {existingApproval && (
                                    <div>
                                        <span className="text-xs text-gray-400 block">Linked Approval Request</span>
                                        <span className="text-gray-800 break-all font-semibold">{existingApproval.id}</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Request Drawer / Form Panel */}
                    {showRequestForm && isEligible && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6 border-l-4 border-l-blue-500">
                            <h3 className="text-md font-bold text-gray-900 mb-2">Request Controlled Reversal</h3>
                            <p className="text-xs text-gray-500 mb-4">
                                Submit approval request for original transaction. Note that all quantities, costs, and valuation sequence allocations are server-controlled and immutable.
                            </p>
                            <form onSubmit={onRequestSubmit} className="space-y-4">
                                <div>
                                    <label htmlFor="reversal_reason" className="block text-xs font-semibold text-gray-600 uppercase mb-1">
                                        Reversal Reason
                                    </label>
                                    <textarea
                                        id="reversal_reason"
                                        rows={3}
                                        value={requestForm.data.reversal_reason}
                                        onChange={(e) => requestForm.setData('reversal_reason', e.target.value)}
                                        className="w-full p-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                                        placeholder="Explain why this reversal is necessary..."
                                        required
                                    />
                                    {requestForm.errors.reversal_reason && (
                                        <span className="text-xs text-red-500 mt-1 block">{requestForm.errors.reversal_reason}</span>
                                    )}
                                </div>
                                <input type="hidden" name="request_idempotency_key" value={requestForm.data.request_idempotency_key} />
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setShowRequestForm(false);
                                            requestForm.reset('reversal_reason');
                                        }}
                                        className="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium py-2 rounded transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={requestForm.processing}
                                        className="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 rounded transition-colors disabled:opacity-50"
                                    >
                                        {requestForm.processing ? 'Submitting...' : 'Submit Request'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* Controlled Confirmation Panel (State 2 Execute) */}
                    {showExecuteForm && isExecutionAvailable && existingApproval && (
                        <div className="bg-white rounded border border-gray-200 shadow-sm p-6 border-l-4 border-l-green-500 space-y-4">
                            <div>
                                <h3 className="text-md font-bold text-gray-900">Review and Execute Reversal</h3>
                                <p className="text-xs text-gray-500">
                                    Review immutable transaction and approval evidence before controlled execution.
                                </p>
                            </div>

                            <div className="bg-gray-50 p-3 rounded text-xs space-y-3">
                                <div>
                                    <strong className="block text-gray-400 uppercase tracking-wider text-[10px]">Original Transaction</strong>
                                    <span className="block font-semibold text-gray-800 break-all">{transaction.id}</span>
                                    <span className="block text-gray-600 capitalize">
                                        Type: {transaction.transaction_type.replace('_', ' ')}
                                    </span>
                                    <span className="block text-gray-600">
                                        Quantity Change: {transaction.quantity_change}
                                    </span>
                                    <span className="block text-gray-600">
                                        Total Cost: {transaction.currency_code} {transaction.total_cost ?? '0.00'}
                                    </span>
                                </div>

                                <div>
                                    <strong className="block text-gray-400 uppercase tracking-wider text-[10px]">Final Approved Request</strong>
                                    <span className="block font-semibold text-gray-800 break-all">{existingApproval.id}</span>
                                    <span className="block text-gray-600">Requested by: {requesterName ?? 'N/A'}</span>
                                    <span className="block text-gray-600">Reason: {existingApproval.notes?.reversal_reason}</span>
                                    <span className="block text-gray-600">Approved by: {approverName ?? 'N/A'}</span>
                                </div>

                                <div>
                                    <strong className="block text-gray-400 uppercase tracking-wider text-[10px]">Expected Operational Result</strong>
                                    <span className="block text-gray-600">
                                        • Original transaction remains immutable.
                                    </span>
                                    <span className="block text-gray-600">
                                        • A new linked reversal transaction will be created.
                                    </span>
                                    <span className="block text-gray-600">
                                        • Physical stock and carrying-value changes will be processed on the backend.
                                    </span>
                                    <span className="block text-gray-600">
                                        • Backend will perform final controlled validation and record audit evidence.
                                    </span>
                                </div>
                            </div>

                            <form onSubmit={onExecuteSubmit} className="space-y-4">
                                <input type="hidden" name="execution_idempotency_key" value={executeForm.data.execution_idempotency_key} />
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setShowExecuteForm(false)}
                                        className="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium py-2 rounded transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={executeForm.processing}
                                        className="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 rounded transition-colors disabled:opacity-50"
                                    >
                                        {executeForm.processing ? 'Executing...' : 'Execute Reversal'}
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
