import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { RoomInspection, EnumOption } from '@/Types';
import { useState } from 'react';
import axios from 'axios';

interface Props {
    inspection: RoomInspection;
    severities: EnumOption[];
}

function statusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        pending:     'bg-gray-100 text-gray-600',
        in_progress: 'bg-yellow-100 text-yellow-700',
        passed:      'bg-green-100 text-green-700',
        failed:      'bg-red-100 text-red-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

function severityBadge(severity: EnumOption | null) {
    if (!severity) return null;
    const classes: Record<string, string> = {
        minor:    'bg-yellow-100 text-yellow-700',
        major:    'bg-orange-100 text-orange-700',
        critical: 'bg-red-100 text-red-700',
    };
    const cls = classes[String(severity.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${cls}`}>
            {severity.label}
        </span>
    );
}

export default function InspectionShow({ inspection, severities }: Props) {
    const [actionLoading, setActionLoading] = useState(false);
    const [remarks, setRemarks]             = useState('');
    const [severity, setSeverity]           = useState('');
    const [showResultForm, setShowResultForm] = useState<'pass' | 'fail' | null>(null);
    const statusVal = String(inspection.status.value);

    function conduct() {
        if (!confirm('Start this inspection?')) return;
        router.post(`/operations/inspections/${inspection.id}/conduct`, {}, { preserveScroll: true });
    }

    function submitResult(outcome: 'pass' | 'fail') {
        setActionLoading(true);
        axios.post(`/operations/inspections/${inspection.id}/${outcome}`, {
            remarks:              remarks || undefined,
            inspection_severity:  severity || undefined,
        })
            .then(() => router.reload())
            .finally(() => setActionLoading(false));
    }

    const isTerminal = statusVal === 'passed' || statusVal === 'failed';

    return (
        <AppLayout>
            <div className="flex items-center gap-3 mb-6">
                <Link href="/operations/inspections" className="text-sm text-gray-500 hover:text-gray-700">
                    ← Inspections
                </Link>
            </div>

            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 flex-wrap">
                    <h1 className="text-2xl font-bold text-gray-900">Inspection</h1>
                    {statusBadge(inspection.status)}
                    {severityBadge(inspection.inspection_severity)}
                </div>
                {!isTerminal && (
                    <Link
                        href={`/operations/inspections/${inspection.id}/edit`}
                        className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                    >
                        Edit
                    </Link>
                )}
            </div>

            {/* Inspection Details */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Details</h2>
                <div className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Room</p>
                        {inspection.room ? (
                            <Link
                                href={`/operations/rooms/${inspection.room_id}`}
                                className="text-sm text-blue-600 hover:text-blue-800"
                            >
                                {inspection.room.room_number}
                            </Link>
                        ) : (
                            <p className="text-sm text-gray-700">—</p>
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Type</p>
                        <p className="text-sm text-gray-700">{inspection.inspection_type.label}</p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Inspector</p>
                        <p className="text-sm text-gray-700">
                            {inspection.inspector?.name ?? '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 mb-1">Inspected At</p>
                        <p className="text-sm text-gray-700">{inspection.inspected_at ?? '—'}</p>
                    </div>
                </div>

                {inspection.task && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Related Task</p>
                        <Link
                            href={`/operations/cleaning-tasks/${inspection.task.id}`}
                            className="text-sm text-blue-600 hover:text-blue-800"
                        >
                            {inspection.task.task_code} — {inspection.task.title}
                        </Link>
                    </div>
                )}

                {inspection.remarks && (
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <p className="text-xs text-gray-500 mb-1">Remarks</p>
                        <p className="text-sm text-gray-700">{inspection.remarks}</p>
                    </div>
                )}

                <div className="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                    Created {inspection.created_at}
                </div>
            </div>

            {/* Actions */}
            {!isTerminal && (
                <div className="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Actions</h2>

                    {statusVal === 'pending' && (
                        <div className="flex gap-3">
                            <button
                                onClick={conduct}
                                className="bg-yellow-500 text-white px-4 py-2 rounded text-sm hover:bg-yellow-600"
                            >
                                Start Inspection
                            </button>
                        </div>
                    )}

                    {statusVal === 'in_progress' && !showResultForm && (
                        <div className="flex gap-3">
                            <button
                                onClick={() => setShowResultForm('pass')}
                                className="bg-green-600 text-white px-4 py-2 rounded text-sm hover:bg-green-700"
                            >
                                Pass
                            </button>
                            <button
                                onClick={() => setShowResultForm('fail')}
                                className="bg-red-600 text-white px-4 py-2 rounded text-sm hover:bg-red-700"
                            >
                                Fail
                            </button>
                        </div>
                    )}

                    {showResultForm && (
                        <div className="mt-4 pt-4 border-t border-gray-100 space-y-4">
                            <p className="text-sm font-medium text-gray-700">
                                Recording: <span className={showResultForm === 'pass' ? 'text-green-600' : 'text-red-600'}>
                                    {showResultForm === 'pass' ? 'Pass' : 'Fail'}
                                </span>
                            </p>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Severity</label>
                                    <select
                                        value={severity}
                                        onChange={(e) => setSeverity(e.target.value)}
                                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option value="">— None —</option>
                                        {severities.map((s) => (
                                            <option key={String(s.value)} value={String(s.value)}>{s.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                                    <input
                                        type="text"
                                        value={remarks}
                                        onChange={(e) => setRemarks(e.target.value)}
                                        placeholder="Optional remarks…"
                                        className="border border-gray-300 rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                            </div>

                            <div className="flex gap-3">
                                <button
                                    onClick={() => submitResult(showResultForm)}
                                    disabled={actionLoading}
                                    className={`text-white px-4 py-2 rounded text-sm disabled:opacity-60 ${
                                        showResultForm === 'pass'
                                            ? 'bg-green-600 hover:bg-green-700'
                                            : 'bg-red-600 hover:bg-red-700'
                                    }`}
                                >
                                    {actionLoading ? '…' : `Confirm ${showResultForm === 'pass' ? 'Pass' : 'Fail'}`}
                                </button>
                                <button
                                    onClick={() => setShowResultForm(null)}
                                    className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200"
                                >
                                    Back
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Terminal state summary */}
            {isTerminal && (
                <div className={`rounded-lg p-4 mb-6 ${statusVal === 'passed' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'}`}>
                    <p className={`text-sm font-medium ${statusVal === 'passed' ? 'text-green-700' : 'text-red-700'}`}>
                        Inspection {statusVal === 'passed' ? 'passed' : 'failed'}
                        {inspection.inspection_severity && ` — ${inspection.inspection_severity.label} severity`}
                    </p>
                    {inspection.remarks && (
                        <p className={`text-xs mt-1 ${statusVal === 'passed' ? 'text-green-600' : 'text-red-600'}`}>
                            {inspection.remarks}
                        </p>
                    )}
                    {inspection.inspected_at && (
                        <p className="text-xs text-gray-500 mt-1">at {inspection.inspected_at}</p>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
