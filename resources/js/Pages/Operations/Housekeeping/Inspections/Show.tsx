import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { EnumOption, InspectionClaimReassignmentContext, InspectionPassContext, RoomInspection } from '@/Types';
import axios from 'axios';
import { useState } from 'react';

interface Props {
    inspection: RoomInspection;
    severities: EnumOption[];
    pass_context: InspectionPassContext | null;
    reassignment_context: InspectionClaimReassignmentContext;
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

export default function InspectionShow({ inspection, severities, pass_context, reassignment_context }: Props) {
    const status = String(inspection.status.value);
    const terminal = status === 'passed' || status === 'failed';
    const [action, setAction] = useState<'pass' | 'fail' | null>(null);
    const [severity, setSeverity] = useState('');
    const [failureReason, setFailureReason] = useState('');
    const [releaseReason, setReleaseReason] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    const [claimOpen, setClaimOpen] = useState(false);
    const [claimKey, setClaimKey] = useState<string | null>(null);
    const [claimAmbiguous, setClaimAmbiguous] = useState(false);
    const [confirmedRelease, setConfirmedRelease] = useState<{ reason: string; evidenceKey: string } | null>(null);
    const [reassignOpen, setReassignOpen] = useState(false);
    const [replacementId, setReplacementId] = useState('');
    const [reassignmentReason, setReassignmentReason] = useState('');
    const [reassignmentPassword, setReassignmentPassword] = useState('');
    const [reassignmentKey, setReassignmentKey] = useState<string | null>(null);
    const [reassignmentConfirmed, setReassignmentConfirmed] = useState(false);
    const [reassignmentAmbiguous, setReassignmentAmbiguous] = useState(false);
    const evidenceKey = pass_context ? JSON.stringify({
        room_number: pass_context.room_number,
        inspection_status: pass_context.inspection_status,
        target_readiness: pass_context.target_readiness,
        cleaning_task_code: pass_context.cleaning_task_code,
    }) : '';
    const confirmationMatches = confirmedRelease?.reason === releaseReason.trim()
        && confirmedRelease.evidenceKey === evidenceKey;

    const openClaim = () => {
        setClaimKey((current) => current ?? window.crypto.randomUUID());
        setClaimOpen(true);
        setError('');
    };

    const claimInspection = async () => {
        const idempotencyKey = claimKey ?? window.crypto.randomUUID();
        setClaimKey(idempotencyKey);
        setProcessing(true);
        setError('');

        try {
            await axios.post(`/operations/inspections/${inspection.id}/conduct`, {
                idempotency_key: idempotencyKey,
            });
            setClaimOpen(false);
            setClaimAmbiguous(false);
            router.reload();
        } catch (requestError: any) {
            if (!requestError?.response) {
                setClaimAmbiguous(true);
                setError('The response was interrupted. Retry with the retained claim command to recover any committed claim.');
            } else {
                setError(requestError.response.data?.message ?? 'The Inspection could not be claimed.');
            }
        } finally {
            setProcessing(false);
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

    const passInspection = async () => {
        setProcessing(true);
        setError('');
        const reason = releaseReason.trim();
        let executionWasConfirmed = confirmationMatches;

        try {
            if (!confirmationMatches) {
                await axios.post(`/operations/inspections/${inspection.id}/pass-confirmation`, {
                    release_reason: reason,
                    password,
                });
                setConfirmedRelease({ reason, evidenceKey });
                setPassword('');
                executionWasConfirmed = true;
            }

            await axios.post(`/operations/inspections/${inspection.id}/pass`, {
                release_reason: reason,
                inspection_severity: severity || undefined,
            });
            router.reload();
        } catch (requestError: any) {
            const responseMessage = requestError?.response?.data?.message;
            const confirmationIsStale = /confirmation|expired|stale|evidence|mismatch|conflict/i.test(responseMessage ?? '');

            if (executionWasConfirmed && !requestError?.response) {
                setError('The response was interrupted after confirmation. The Room may already be released; retry to recover the committed result without entering the password again.');
            } else {
                if (executionWasConfirmed && confirmationIsStale) {
                    setConfirmedRelease(null);
                }
                setError(responseMessage ?? 'The Room release could not be completed.');
            }
        } finally {
            setProcessing(false);
        }
    };

    const closePassModal = () => {
        setAction(null);
        setPassword('');
        setConfirmedRelease(null);
        setError('');
    };

    const openReassignment = () => {
        setReassignmentKey((current) => current ?? window.crypto.randomUUID());
        setReplacementId((current) => current || reassignment_context.replacement_candidates[0]?.id || '');
        setReassignOpen(true);
        setError('');
    };

    const reassignInspector = async () => {
        const idempotencyKey = reassignmentKey ?? window.crypto.randomUUID();
        setReassignmentKey(idempotencyKey);
        setProcessing(true);
        setError('');
        let confirmed = reassignmentConfirmed;

        try {
            if (!confirmed) {
                await axios.post(`/operations/inspections/${inspection.id}/claim-reassignment-confirmation`, {
                    replacement_inspector_id: replacementId,
                    reason: reassignmentReason.trim(),
                    idempotency_key: idempotencyKey,
                    password: reassignmentPassword,
                });
                confirmed = true;
                setReassignmentConfirmed(true);
                setReassignmentPassword('');
            }

            await axios.post(`/operations/inspections/${inspection.id}/claim-reassignment`, {
                replacement_inspector_id: replacementId,
                reason: reassignmentReason.trim(),
                idempotency_key: idempotencyKey,
            });
            setReassignmentAmbiguous(false);
            router.reload();
        } catch (requestError: any) {
            if (confirmed && !requestError?.response) {
                setReassignmentAmbiguous(true);
                setError('The response was interrupted. Retry the retained command to recover any committed reassignment without entering the password again.');
            } else {
                if (/confirmation|source|eligib|conflict/i.test(requestError?.response?.data?.message ?? '')) {
                    setReassignmentConfirmed(false);
                }
                setError(requestError?.response?.data?.message ?? 'The inspector could not be reassigned.');
            }
        } finally {
            setProcessing(false);
        }
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
                <dl className="grid grid-cols-2 gap-6 md:grid-cols-5">
                    <div><dt className="text-xs text-gray-500">Room</dt><dd className="mt-1 text-sm font-medium text-gray-900">{inspection.room?.room_number ?? 'Unavailable'}</dd></div>
                    <div><dt className="text-xs text-gray-500">Type</dt><dd className="mt-1 text-sm text-gray-700">{inspection.inspection_type.label}</dd></div>
                    <div><dt className="text-xs text-gray-500">Cleaner</dt><dd className="mt-1 text-sm text-gray-700">{inspection.task?.completed_by_name ?? 'Unavailable'}</dd></div>
                    <div><dt className="text-xs text-gray-500">Original claimant</dt><dd className="mt-1 text-sm text-gray-700">{inspection.claim.original_claimant_name ?? 'Not claimed'}</dd></div>
                    <div><dt className="text-xs text-gray-500">Cleaning Task</dt><dd className="mt-1 text-sm text-gray-700">{inspection.task?.task_code ?? 'Unavailable'}</dd></div>
                </dl>
                {inspection.remarks && <p className="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-700">{inspection.remarks}</p>}
                {inspection.reassignment && <div className="mt-5 border-t border-gray-100 pt-5">
                    <h3 className="text-sm font-semibold text-gray-900">Controlled claim recovery</h3>
                    <dl className="mt-3 grid grid-cols-2 gap-4 text-sm md:grid-cols-3">
                        <div><dt className="text-gray-500">Original claimant</dt><dd className="font-medium text-gray-900">{inspection.claim.original_claimant_name ?? 'Unavailable'}</dd></div>
                        <div><dt className="text-gray-500">Effective claimant</dt><dd className="font-medium text-gray-900">{inspection.reassignment.replacement_claimant_name ?? 'Unavailable'}</dd></div>
                        <div><dt className="text-gray-500">Intervened by</dt><dd className="font-medium text-gray-900">{inspection.reassignment.intervenor_name ?? 'Unavailable'}</dd></div>
                        <div><dt className="text-gray-500">Objective recovery reason</dt><dd className="font-medium text-gray-900">{inspection.reassignment.original_ineligibility_code}</dd></div>
                        <div><dt className="text-gray-500">Human reason</dt><dd className="font-medium text-gray-900">{inspection.reassignment.reason}</dd></div>
                        <div><dt className="text-gray-500">Occurred at</dt><dd className="font-medium text-gray-900">{inspection.reassignment.occurred_at}</dd></div>
                    </dl>
                    <p className="mt-3 text-xs text-gray-500">The original Package 17 claimant evidence remains unchanged; terminal authority now follows the effective claimant.</p>
                </div>}
            </section>

            {!terminal && (
                <section className="mb-6 rounded-lg bg-white p-6 shadow">
                    <h2 className="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">Actions</h2>
                    {status === 'pending' && (
                        inspection.claim.can_claim ? (
                            <button onClick={openClaim} className="rounded bg-yellow-700 px-4 py-2 text-sm text-white hover:bg-yellow-800">Claim Inspection</button>
                        ) : (
                            <p className="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                {inspection.claim.is_current_actor_cleaner
                                    ? 'You completed this Cleaning Task and cannot inspect the same work.'
                                    : 'This Inspection is not available for you to claim.'}
                            </p>
                        )
                    )}
                    {status === 'in_progress' && action === null && (
                        <div className="space-y-3">
                        {inspection.claim.can_pass || inspection.claim.can_fail ? <div className="flex gap-3">
                            <button
                                onClick={() => setAction('pass')}
                                disabled={!inspection.claim.can_pass || !pass_context}
                                className="rounded bg-green-700 px-4 py-2 text-sm text-white hover:bg-green-800 disabled:opacity-50"
                            >
                                Pass Inspection
                            </button>
                            {inspection.claim.can_fail && <button onClick={() => setAction('fail')} className="rounded bg-red-700 px-4 py-2 text-sm text-white hover:bg-red-800">Fail Inspection</button>}
                        </div> : !inspection.claim.can_reassign && <p className="rounded border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">Terminal actions are available only to the effective inspector claimant.</p>}
                        {inspection.claim.can_reassign && reassignment_context.may_intervene && reassignment_context.replacement_candidates.length > 0 && (
                            <button onClick={openReassignment} className="rounded bg-indigo-700 px-4 py-2 text-sm text-white hover:bg-indigo-800">Reassign Inspector</button>
                        )}
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

            {claimOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="claim-title">
                    <div className="w-full max-w-lg rounded-lg bg-white shadow-xl">
                        <div className="border-b border-gray-200 px-6 py-4">
                            <h2 id="claim-title" className="text-lg font-semibold text-gray-900">Claim Post-cleaning Inspection</h2>
                            <p className="mt-1 text-sm text-gray-600">Review the maker-checker boundary before accepting this controlled action.</p>
                        </div>
                        <div className="space-y-4 px-6 py-5 text-sm text-gray-700">
                            <dl className="grid grid-cols-2 gap-3 rounded bg-gray-50 p-4">
                                <div><dt className="text-gray-500">Room</dt><dd className="font-medium">{inspection.room?.room_number ?? 'Unavailable'}</dd></div>
                                <div><dt className="text-gray-500">Cleaning Task</dt><dd className="font-medium">{inspection.task?.task_code ?? 'Unavailable'}</dd></div>
                                <div className="col-span-2"><dt className="text-gray-500">Completed cleaner</dt><dd className="font-medium">{inspection.task?.completed_by_name ?? 'Unavailable'}</dd></div>
                            </dl>
                            <ul className="list-disc space-y-2 pl-5">
                                <li>You will become the immutable inspector claimant for this Inspection.</li>
                                <li>The cleaner who completed this task cannot inspect their own work.</li>
                                <li>Another inspector cannot silently take over after the claim commits.</li>
                            </ul>
                            {claimAmbiguous && <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">The same in-memory command will be reused for this recovery retry.</p>}
                            {error && <p role="alert" className="rounded border border-red-200 bg-red-50 px-3 py-2 text-red-700">{error}</p>}
                        </div>
                        <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                            <button onClick={() => { setClaimOpen(false); setError(''); }} disabled={processing} className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-700 disabled:opacity-50">Cancel</button>
                            <button onClick={claimInspection} disabled={processing} className="rounded bg-yellow-700 px-4 py-2 text-sm text-white disabled:opacity-50">
                                {processing ? 'Claiming Inspection...' : claimAmbiguous ? 'Retry Claim Recovery' : 'Confirm Inspection Claim'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {reassignOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="reassign-title">
                    <div className="w-full max-w-xl rounded-lg bg-white shadow-xl">
                        <div className="border-b border-gray-200 px-6 py-4">
                            <h2 id="reassign-title" className="text-lg font-semibold text-gray-900">Reassign Inspector</h2>
                            <p className="mt-1 text-sm text-gray-600">Recover terminal authority without changing the immutable original claim.</p>
                        </div>
                        <div className="space-y-4 px-6 py-5">
                            <dl className="grid grid-cols-2 gap-3 rounded bg-gray-50 p-4 text-sm">
                                <div><dt className="text-gray-500">Room</dt><dd className="font-medium">{inspection.room?.room_number ?? 'Unavailable'}</dd></div>
                                <div><dt className="text-gray-500">Cleaning Task</dt><dd className="font-medium">{inspection.task?.task_code ?? 'Unavailable'}</dd></div>
                                <div><dt className="text-gray-500">Completed cleaner</dt><dd className="font-medium">{inspection.task?.completed_by_name ?? 'Unavailable'}</dd></div>
                                <div><dt className="text-gray-500">Original claimant</dt><dd className="font-medium">{reassignment_context.original_claimant_name ?? 'Unavailable'}</dd></div>
                                <div className="col-span-2"><dt className="text-gray-500">Objective ineligibility reason</dt><dd className="font-medium">{reassignment_context.original_ineligibility_code}</dd></div>
                            </dl>
                            <div>
                                <label htmlFor="replacement-inspector" className="block text-sm font-medium text-gray-700">Replacement inspector</label>
                                <select id="replacement-inspector" value={replacementId} onChange={(event) => { setReplacementId(event.target.value); setReassignmentConfirmed(false); }} className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                    {reassignment_context.replacement_candidates.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <label htmlFor="reassignment-reason" className="block text-sm font-medium text-gray-700">Reason</label>
                                <textarea id="reassignment-reason" value={reassignmentReason} onChange={(event) => { setReassignmentReason(event.target.value); setReassignmentConfirmed(false); }} rows={3} className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                            {!reassignmentConfirmed && <div>
                                <label htmlFor="reassignment-password" className="block text-sm font-medium text-gray-700">Current password</label>
                                <input id="reassignment-password" type="password" value={reassignmentPassword} onChange={(event) => setReassignmentPassword(event.target.value)} autoComplete="current-password" className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                            </div>}
                            {reassignmentAmbiguous && <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">The exact in-memory command is retained for a recovery retry.</p>}
                            {error && <p role="alert" className="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
                        </div>
                        <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                            <button onClick={() => { setReassignOpen(false); setError(''); }} disabled={processing} className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-700 disabled:opacity-50">Cancel</button>
                            <button onClick={reassignInspector} disabled={processing || replacementId === '' || reassignmentReason.trim() === '' || (!reassignmentConfirmed && reassignmentPassword === '')} className="rounded bg-indigo-700 px-4 py-2 text-sm text-white disabled:opacity-50">
                                {processing ? 'Reassigning Inspector...' : reassignmentAmbiguous ? 'Retry Reassignment Recovery' : 'Confirm and Reassign Inspector'}
                            </button>
                        </div>
                    </div>
                </div>
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
                                <textarea id="release-reason" value={releaseReason} onChange={(event) => {
                                    setReleaseReason(event.target.value);
                                    if (confirmedRelease?.reason !== event.target.value.trim()) {
                                        setConfirmedRelease(null);
                                    }
                                }} rows={3} autoFocus className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                            {confirmationMatches ? (
                                <p className="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">Confirmation is retained in memory for an exact recovery retry.</p>
                            ) : (
                                <div>
                                    <label htmlFor="release-password" className="block text-sm font-medium text-gray-700">Current password</label>
                                    <input id="release-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" className="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                                </div>
                            )}
                            {error && <p role="alert" className="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
                        </div>
                        <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                            <button onClick={closePassModal} className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-700">Cancel</button>
                            <button onClick={passInspection} disabled={processing || releaseReason.trim() === '' || (!confirmationMatches && password === '')} className="rounded bg-green-700 px-4 py-2 text-sm text-white disabled:opacity-50">
                                {processing ? 'Releasing Room...' : confirmationMatches ? 'Retry Room Release' : 'Confirm and Release Room'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
