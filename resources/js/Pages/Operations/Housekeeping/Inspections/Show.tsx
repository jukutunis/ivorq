import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { EnumOption, InspectionPassContext, RoomInspection } from '@/Types';
import axios from 'axios';
import { useState } from 'react';

interface Props {
    inspection: RoomInspection;
    severities: EnumOption[];
    pass_context: InspectionPassContext | null;
}

function statusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        pending: 'bg-gray-100 text-gray-600',
        in_progress: 'bg-yellow-100 text-yellow-700',
        passed: 'bg-green-100 text-green-700',
        failed: 'bg-red-100 text-red-700',
    };

    return (
        <span className={`inline-flex rounded px-2.5 py-0.5 text-xs font-medium ${classes[String(status.value)] ?? 'bg-gray-100 text-gray-700'}`}>
            {status.label}
        </span>
    );
}

export default function InspectionShow({ inspection, severities, pass_context }: Props) {
    const status = String(inspection.status.value);
    const terminal = status === 'passed' || status === 'failed';
    const [action, setAction] = useState<'pass' | 'fail' | null>(null);
    const [severity, setSeverity] = useState('');
    const [failureReason, setFailureReason] = useState('');
    const [releaseReason, setReleaseReason] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');

    const conduct = () => {
        if (window.confirm('Start this inspection?')) {
            router.post(`/operations/inspections/${inspection.id}/conduct`, {}, { preserveScroll: true });
        }
    };

    const failInspection = () => {
        setProcessing(true);
        setError('');
        axios.post(`/operations/inspections/${inspection.id}/fail`, {
            remarks: failureReason,
            inspection_severity: severity || undefined,
        })
            .then(() => router.reload())
            .catch((requestError) => setError(requestError?.response?.data?.message ?? 'The inspection could not be failed.'))
            .finally(() => setProcessing(false));
    };

    const passInspection = () => {
        setProcessing(true);
        setError('');
        axios.post(`/operations/inspections/${inspection.id}/pass-confirmation`, {
            release_reason: releaseReason,
            password,
        })
            .then(() => axios.post(`/operations/inspections/${inspection.id}/pass`, {
                release_reason: releaseReason,
                inspection_severity: severity || undefined,
            }))
            .then(() => router.reload())
            .catch((requestError) => setError(requestError?.response?.data?.message ?? 'The Room release could not be completed.'))
            .finally(() => setProcessing(false));
    };

    return (
        <AppLayout>
            <div className="mb-6">
                <Link href="/operations/inspections" className="text-sm text-gray-500 hover:text-gray-700">Back to Inspections</Link>
            </div>

            <div className="mb-6 flex items-center justify-between">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-bold text-gray-900">Room Inspection</h1>
                    {statusBadge(inspection.status)}
                </div>
                {!terminal && (
                    <Link href={`/operations/inspections/${inspection.id}/edit`} className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">
                        Edit
                    </Link>
                )}
            </div>

            <section className="mb-6 rounded-lg bg-white p-6 shadow">
                <h2 className="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">Operational evidence</h2>
                <dl className="grid grid-cols-2 gap-6 md:grid-cols-4">
                    <div><dt className="text-xs text-gray-500">Room</dt><dd className="mt-1 text-sm font-medium text-gray-900">{inspection.room?.room_number ?? 'Unavailable'}</dd></div>
                    <div><dt className="text-xs text-gray-500">Type</dt><dd className="mt-1 text-sm text-gray-700">{inspection.inspection_type.label}</dd></div>
                    <div><dt className="text-xs text-gray-500">Supervisor</dt><dd className="mt-1 text-sm text-gray-700">{inspection.inspector?.name ?? 'Not started'}</dd></div>
                    <div><dt className="text-xs text-gray-500">Cleaning Task</dt><dd className="mt-1 text-sm text-gray-700">{inspection.task?.task_code ?? 'Unavailable'}</dd></div>
                </dl>
                {inspection.remarks && <p className="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-700">{inspection.remarks}</p>}
            </section>

            {!terminal && (
                <section className="mb-6 rounded-lg bg-white p-6 shadow">
                    <h2 className="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">Actions</h2>
                    {status === 'pending' && (
                        <button onClick={conduct} className="rounded bg-yellow-600 px-4 py-2 text-sm text-white hover:bg-yellow-700">Start Inspection</button>
                    )}
                    {status === 'in_progress' && action === null && (
                        <div className="flex gap-3">
                            <button
                                onClick={() => setAction('pass')}
                                disabled={!pass_context}
                                className="rounded bg-green-700 px-4 py-2 text-sm text-white hover:bg-green-800 disabled:opacity-50"
                            >
                                Pass Inspection
                            </button>
                            <button onClick={() => setAction('fail')} className="rounded bg-red-700 px-4 py-2 text-sm text-white hover:bg-red-800">Fail Inspection</button>
                        </div>
                    )}
                    {action === 'fail' && (
                        <div className="max-w-2xl space-y-4 border-t border-gray-100 pt-4">
                            <p className="text-sm text-gray-600">Failure returns the Room to waiting cleaning and creates one source-bound re-cleaning task.</p>
                            <div>
                                <label htmlFor="failure-reason" className="block text-sm font-medium text-gray-700">Failure reason</label>
                                <textarea id="failure-reason" value={failureReason} onChange={(event) => setFailureReason(event.target.value)} rows={3} className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label htmlFor="failure-severity" className="block text-sm font-medium text-gray-700">Severity</label>
                                <select id="failure-severity" value={severity} onChange={(event) => setSeverity(event.target.value)} className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                    <option value="">None</option>
                                    {severities.map((option) => <option key={String(option.value)} value={String(option.value)}>{option.label}</option>)}
                                </select>
                            </div>
                            {error && <p role="alert" className="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
                            <div className="flex gap-3">
                                <button onClick={failInspection} disabled={processing || failureReason.trim() === ''} className="rounded bg-red-700 px-4 py-2 text-sm text-white disabled:opacity-50">
                                    {processing ? 'Recording failure...' : 'Confirm Failure and Re-cleaning'}
                                </button>
                                <button onClick={() => { setAction(null); setError(''); }} className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-700">Cancel</button>
                            </div>
                        </div>
                    )}
                </section>
            )}

            {terminal && (
                <section className={`rounded-lg border p-4 ${status === 'passed' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'}`}>
                    <p className="text-sm font-semibold">Inspection {status}</p>
                    {inspection.inspected_at && <p className="mt-1 text-xs">Recorded at {inspection.inspected_at}</p>}
                </section>
            )}

            {action === 'pass' && pass_context && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="release-title">
                    <div className="w-full max-w-lg rounded-lg bg-white shadow-xl">
                        <div className="border-b border-gray-200 px-6 py-4">
                            <h2 id="release-title" className="text-lg font-semibold text-gray-900">Confirm Room Release</h2>
                            <p className="mt-1 text-sm text-gray-600">This sensitive action releases the Room through canonical Housekeeping readiness authority.</p>
                        </div>
                        <div className="space-y-4 px-6 py-5">
                            <dl className="grid grid-cols-2 gap-3 rounded bg-gray-50 p-4 text-sm">
                                <div><dt className="text-gray-500">Room</dt><dd className="font-medium">{pass_context.room_number}</dd></div>
                                <div><dt className="text-gray-500">Inspection</dt><dd className="font-medium">{pass_context.inspection_status}</dd></div>
                                <div><dt className="text-gray-500">Target readiness</dt><dd className="font-medium">{pass_context.target_readiness}</dd></div>
                                <div><dt className="text-gray-500">Cleaning Task</dt><dd className="font-medium">{pass_context.cleaning_task_code}</dd></div>
                            </dl>
                            <div>
                                <label htmlFor="release-reason" className="block text-sm font-medium text-gray-700">Release reason</label>
                                <textarea id="release-reason" value={releaseReason} onChange={(event) => setReleaseReason(event.target.value)} rows={3} autoFocus className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label htmlFor="release-password" className="block text-sm font-medium text-gray-700">Current password</label>
                                <input id="release-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                            {error && <p role="alert" className="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
                        </div>
                        <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                            <button onClick={() => { setAction(null); setPassword(''); setError(''); }} className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-700">Cancel</button>
                            <button onClick={passInspection} disabled={processing || releaseReason.trim() === '' || password === ''} className="rounded bg-green-700 px-4 py-2 text-sm text-white disabled:opacity-50">
                                {processing ? 'Releasing Room...' : 'Confirm and Release Room'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
