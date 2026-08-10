import StatusBadge, { BadgeStatus } from '@/Components/Ivorq/primitives/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, ReactNode, useEffect, useRef, useState } from 'react';

type OperationalState =
    | 'review_required'
    | 'completed'
    | 'delivery_confirmation_pending'
    | 'active_claim'
    | 'ready'
    | 'retry_wait'
    | 'scheduled';

interface TurnoverLinks {
    room: string | null;
    cleaning_task: string | null;
    room_readiness: string | null;
}

interface Turnover {
    handoff_id: string;
    intake_id: string | null;
    room_id: string | null;
    room_number: string | null;
    room_floor: string | null;
    reservation_number: string | null;
    checkout_execution_id: string;
    business_date: string;
    occurred_at: string | null;
    available_at: string | null;
    claimed_at: string | null;
    claim_expires_at: string | null;
    failed_at: string | null;
    delivered_at: string | null;
    attempts: number;
    handoff_status: string;
    safe_last_error_code: string | null;
    operational_state: OperationalState;
    review_marker: string | null;
    cleaning_task_id: string | null;
    cleaning_task_code: string | null;
    task_status: string | null;
    task_priority: string | null;
    task_started_at: string | null;
    readiness_transition_id: string | null;
    readiness_transition_type: string | null;
    readiness_state: string | null;
    cleanliness_state: string | null;
    room_readiness_before: string | null;
    room_readiness_after: string | null;
    cleanliness_before: string | null;
    cleanliness_after: string | null;
    consumer_identity: string | null;
    intake_committed_at: string | null;
    committed: boolean;
    replayed_evidence: boolean | null;
    terminal_stay_evidence: boolean;
    last_event_age_seconds: number;
    links: TurnoverLinks;
    active_assignment?: ActiveAssignment | null;
    assignment_history_summary?: AssignmentHistorySummary;
    assignment_actions?: AssignmentActions;
    eligible_attendants?: EligibleAttendant[];
    attendant_workload?: AttendantWorkload[];
}

interface ActiveAssignment {
    assignment_id: string;
    user_id: string;
    user_name: string | null;
    department_id: string | null;
    department_name: string | null;
    assignment_status: string;
    previous_assignment_id: string | null;
    assigned_at: string | null;
}

interface AssignmentHistorySummary {
    total: number;
    reassigned: number;
    completed: number;
    cancelled: number;
}

interface AssignmentActions {
    can_assign: boolean;
    can_reassign: boolean;
    assignment_blockers: string[];
}

interface EligibleAttendant {
    user_id: string;
    display_name: string;
    department_id: string;
    department_name: string;
}

interface AttendantWorkload extends EligibleAttendant {
    active_assignment_count: number;
    assigned_not_started_count: number;
    in_progress_count: number;
    rush_assignment_count: number;
    active_credits: number;
    oldest_active_assignment_at: string | null;
}

interface AssignmentReceipt {
    assignment_id: string;
    assignment_action: 'initial' | 'reassignment';
    user_name: string;
    department_name: string;
    assigned_at: string | null;
    replayed: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedTurnovers {
    data: Turnover[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
}

interface Filters {
    state: OperationalState | null;
    search: string | null;
    business_date: string | null;
    task_status: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
    selected: string | null;
}

interface Props {
    turnovers: PaginatedTurnovers;
    kpis: {
        ready_now: number;
        active_claims: number;
        retry_waiting: number;
        delivery_confirmation_pending: number;
        completed_today: number;
        review_required: number;
    };
    filters: Filters;
    selected_turnover: Turnover | null;
    options: {
        states: OperationalState[];
        task_statuses: Array<{ value: string; label: string }>;
        sorts: string[];
    };
}

const STATE_META: Record<OperationalState, { label: string; badge: BadgeStatus }> = {
    ready: { label: 'Ready', badge: 'ready' },
    active_claim: { label: 'Active Claim', badge: 'info' },
    retry_wait: { label: 'Retry Waiting', badge: 'warning' },
    delivery_confirmation_pending: { label: 'Pending Confirmation', badge: 'pending' },
    completed: { label: 'Completed', badge: 'success' },
    review_required: { label: 'Review Required', badge: 'error' },
    scheduled: { label: 'Scheduled', badge: 'neutral' },
};

const TABS: Array<{ value: OperationalState | null; label: string }> = [
    { value: null, label: 'All' },
    { value: 'ready', label: 'Ready' },
    { value: 'active_claim', label: 'Active Claim' },
    { value: 'retry_wait', label: 'Retry Waiting' },
    { value: 'delivery_confirmation_pending', label: 'Pending Confirmation' },
    { value: 'completed', label: 'Completed' },
    { value: 'review_required', label: 'Review Required' },
];

const KPI_ITEMS: Array<{ key: keyof Props['kpis']; label: string }> = [
    { key: 'ready_now', label: 'Ready now' },
    { key: 'active_claims', label: 'Active claims' },
    { key: 'retry_waiting', label: 'Retry waiting' },
    { key: 'delivery_confirmation_pending', label: 'Pending confirmation' },
    { key: 'completed_today', label: 'Completed today' },
    { key: 'review_required', label: 'Review required' },
];

function formatDateTime(value: string | null): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatAge(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
    return `${Math.floor(seconds / 86400)}d`;
}

function humanize(value: string | null): string {
    if (!value) return '—';
    return value.toLowerCase().replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function StateBadge({ state }: { state: OperationalState }) {
    const meta = STATE_META[state];
    return <StatusBadge status={meta.badge}>{meta.label}</StatusBadge>;
}

function DetailField({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[minmax(0,8rem)_1fr] gap-3 border-b border-gray-100 py-2 last:border-0">
            <dt className="text-xs font-medium text-gray-500">{label}</dt>
            <dd className="min-w-0 break-words text-xs text-gray-900">{children}</dd>
        </div>
    );
}

export default function CheckoutTurnoverWorkspace({
    turnovers,
    kpis,
    filters,
    selected_turnover,
    options,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [drawerAction, setDrawerAction] = useState<'assign' | 'reassign' | null>(null);
    const [selectedUserId, setSelectedUserId] = useState('');
    const [idempotencyKey, setIdempotencyKey] = useState('');
    const [assignmentSubmitting, setAssignmentSubmitting] = useState(false);
    const [assignmentError, setAssignmentError] = useState<string | null>(null);
    const [assignmentReceipt, setAssignmentReceipt] = useState<AssignmentReceipt | null>(null);
    const attendantSelectRef = useRef<HTMLSelectElement>(null);

    useEffect(() => {
        if (drawerAction) attendantSelectRef.current?.focus();
    }, [drawerAction]);

    useEffect(() => {
        if (!drawerAction) return;
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !assignmentSubmitting) closeAssignmentDrawer();
        };
        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    });

    function freshIdempotencyKey(): string {
        return window.crypto.randomUUID();
    }

    function openAssignmentDrawer(action: 'assign' | 'reassign') {
        setDrawerAction(action);
        setSelectedUserId('');
        setIdempotencyKey(freshIdempotencyKey());
        setAssignmentError(null);
        setAssignmentReceipt(null);
    }

    function closeAssignmentDrawer() {
        if (assignmentSubmitting) return;
        setDrawerAction(null);
        setSelectedUserId('');
        setIdempotencyKey('');
        setAssignmentError(null);
    }

    function changeAttendant(userId: string) {
        setSelectedUserId(userId);
        setIdempotencyKey(freshIdempotencyKey());
        setAssignmentError(null);
    }

    async function submitAssignment(event: FormEvent) {
        event.preventDefault();
        if (!selected_turnover?.cleaning_task_id || !drawerAction) return;
        const attendant = selected_turnover.eligible_attendants?.find((item) => item.user_id === selectedUserId);
        if (!attendant) {
            setAssignmentError('Select an eligible attendant.');
            return;
        }
        setAssignmentSubmitting(true);
        setAssignmentError(null);
        try {
            const response = await axios.post<AssignmentReceipt>(
                `/operations/cleaning-tasks/${selected_turnover.cleaning_task_id}/assign`,
                {
                    user_id: attendant.user_id,
                    department_id: attendant.department_id,
                    idempotency_key: idempotencyKey,
                    expected_active_assignment_id: drawerAction === 'reassign'
                        ? selected_turnover.active_assignment?.assignment_id ?? null
                        : null,
                },
                { headers: { Accept: 'application/json' } },
            );
            setAssignmentReceipt(response.data);
            setDrawerAction(null);
            setSelectedUserId('');
            setIdempotencyKey('');
            router.reload({ only: ['turnovers', 'selected_turnover', 'kpis'] });
        } catch (error) {
            if (axios.isAxiosError(error) && !error.response) {
                setAssignmentError('The server outcome was not confirmed. Retry to safely recover the same result.');
            } else {
                setAssignmentError('The assignment could not be committed. Refresh the turnover evidence before trying again.');
            }
        } finally {
            setAssignmentSubmitting(false);
        }
    }

    const selectedWorkload = selected_turnover?.attendant_workload?.find((item) => item.user_id === selectedUserId);

    function navigate(patch: Partial<Filters> & { page?: number | undefined }) {
        const merged: Record<string, string | number | null | undefined> = {
            state: filters.state ?? undefined,
            search: filters.search ?? undefined,
            business_date: filters.business_date ?? undefined,
            task_status: filters.task_status ?? undefined,
            sort: filters.sort,
            direction: filters.direction,
            per_page: filters.per_page,
            selected: filters.selected ?? undefined,
            ...patch,
            page: patch.page,
        };

        const next = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value !== undefined && value !== null),
        ) as Record<string, string | number>;
        router.get('/operations/housekeeping/checkout-turnovers', next, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        navigate({ search: search.trim() || undefined, page: undefined });
    }

    function sortBy(column: string) {
        navigate({
            sort: column,
            direction: filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc',
            page: undefined,
        });
    }

    function selectedUrl(handoffId: string): string {
        const params = new URLSearchParams();
        if (filters.state) params.set('state', filters.state);
        if (filters.search) params.set('search', filters.search);
        if (filters.business_date) params.set('business_date', filters.business_date);
        if (filters.task_status) params.set('task_status', filters.task_status);
        params.set('sort', filters.sort);
        params.set('direction', filters.direction);
        params.set('per_page', String(filters.per_page));
        params.set('selected', handoffId);
        return `/operations/housekeeping/checkout-turnovers?${params.toString()}`;
    }

    const hasFilters = Boolean(filters.search || filters.business_date || filters.task_status || filters.state);

    return (
        <AppLayout>
            <Head title="Checkout Turnover" />

            <header className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Checkout Turnover</h1>
                    <p className="mt-1 text-sm text-gray-500">Housekeeping checkout handoff and turnover intake operations</p>
                </div>
                <Link
                    href="/operations/housekeeping"
                    className="inline-flex min-h-10 items-center justify-center rounded border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    Housekeeping dashboard
                </Link>
            </header>

            <section aria-label="Turnover operational counts" className="mb-4 overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div className="grid grid-cols-2 divide-x divide-y divide-gray-200 sm:grid-cols-3 xl:grid-cols-6 xl:divide-y-0">
                    {KPI_ITEMS.map((item) => (
                        <div key={item.key} className="px-3 py-3">
                            <div className="text-xl font-semibold tabular-nums text-gray-900">{kpis[item.key]}</div>
                            <div className="mt-0.5 text-xs font-medium text-gray-500">{item.label}</div>
                        </div>
                    ))}
                </div>
            </section>

            {kpis.review_required > 0 && (
                <div role="alert" className="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <span className="font-semibold">Operational review required.</span>{' '}
                    {kpis.review_required} turnover {kpis.review_required === 1 ? 'record has' : 'records have'} inconsistent durable evidence.
                </div>
            )}

            <nav aria-label="Operational state" className="mb-4 overflow-x-auto border-b border-gray-200">
                <div className="flex min-w-max gap-1">
                    {TABS.map((tab) => {
                        const active = filters.state === tab.value;
                        return (
                            <button
                                key={tab.label}
                                type="button"
                                onClick={() => navigate({ state: tab.value ?? undefined, page: undefined })}
                                aria-current={active ? 'page' : undefined}
                                className={`min-h-10 border-b-2 px-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 ${
                                    active
                                        ? 'border-blue-600 text-blue-700'
                                        : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-900'
                                }`}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </div>
            </nav>

            <form onSubmit={submitSearch} className="mb-4 grid gap-3 rounded-lg border border-gray-200 bg-white p-3 md:grid-cols-[minmax(14rem,1fr)_10rem_11rem_6rem_auto]">
                <label className="block">
                    <span className="sr-only">Search turnovers</span>
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Room, reservation, handoff, intake, execution, task"
                        className="min-h-10 w-full rounded border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                </label>
                <label className="block">
                    <span className="sr-only">Business Date</span>
                    <input
                        type="date"
                        value={filters.business_date ?? ''}
                        onChange={(event) => navigate({ business_date: event.target.value || undefined, page: undefined })}
                        className="min-h-10 w-full rounded border border-gray-300 px-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                </label>
                <label className="block">
                    <span className="sr-only">Cleaning task status</span>
                    <select
                        value={filters.task_status ?? ''}
                        onChange={(event) => navigate({ task_status: event.target.value || undefined, page: undefined })}
                        className="min-h-10 w-full rounded border border-gray-300 px-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="">All task statuses</option>
                        {options.task_statuses.map((status) => (
                            <option key={status.value} value={status.value}>{status.label}</option>
                        ))}
                    </select>
                </label>
                <label className="block">
                    <span className="sr-only">Rows per page</span>
                    <select
                        value={filters.per_page}
                        onChange={(event) => navigate({ per_page: Number(event.target.value), page: undefined })}
                        className="min-h-10 w-full rounded border border-gray-300 px-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        {[25, 50, 100].map((size) => <option key={size} value={size}>{size} rows</option>)}
                    </select>
                </label>
                <div className="flex gap-2">
                    <button type="submit" className="min-h-10 rounded bg-blue-600 px-4 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Search
                    </button>
                    {hasFilters && (
                        <button
                            type="button"
                            onClick={() => router.get('/operations/housekeeping/checkout-turnovers')}
                            className="min-h-10 rounded border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            Clear
                        </button>
                    )}
                </div>
            </form>

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_23rem]">
                <section aria-label="Checkout turnover records" className="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
                    {turnovers.data.length === 0 ? (
                        <div className="px-6 py-14 text-center">
                            <h2 className="text-sm font-semibold text-gray-900">No checkout turnovers found</h2>
                            <p className="mt-1 text-sm text-gray-500">No durable handoffs match the current operational filters.</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[64rem] text-left text-sm">
                                <thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th scope="col" className="px-3 py-2.5">Room</th>
                                        <th scope="col" className="px-3 py-2.5">State</th>
                                        <th scope="col" className="px-3 py-2.5">
                                            <button type="button" onClick={() => sortBy('business_date')} className="font-semibold hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">Business Date</button>
                                        </th>
                                        <th scope="col" className="px-3 py-2.5">Reservation</th>
                                        <th scope="col" className="px-3 py-2.5">Handoff</th>
                                        <th scope="col" className="px-3 py-2.5">Cleaning task</th>
                                        <th scope="col" className="px-3 py-2.5">
                                            <button type="button" onClick={() => sortBy('attempts')} className="font-semibold hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">Attempts</button>
                                        </th>
                                        <th scope="col" className="px-3 py-2.5">
                                            <button type="button" onClick={() => sortBy('occurred_at')} className="font-semibold hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">Last event</button>
                                        </th>
                                        <th scope="col" className="px-3 py-2.5"><span className="sr-only">Detail</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {turnovers.data.map((turnover) => {
                                        const selected = filters.selected === turnover.handoff_id;
                                        return (
                                            <tr key={turnover.handoff_id} className={selected ? 'bg-blue-50' : 'hover:bg-gray-50'}>
                                                <td className="whitespace-nowrap px-3 py-3 font-semibold text-gray-900">
                                                    {turnover.room_number ?? 'Unknown'}
                                                    {turnover.room_floor && <span className="ml-1 font-normal text-gray-400">· Floor {turnover.room_floor}</span>}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-3"><StateBadge state={turnover.operational_state} /></td>
                                                <td className="whitespace-nowrap px-3 py-3 text-gray-700">{turnover.business_date}</td>
                                                <td className="whitespace-nowrap px-3 py-3 font-mono text-xs text-gray-600">{turnover.reservation_number ?? '—'}</td>
                                                <td className="px-3 py-3">
                                                    <div className="font-medium text-gray-800">{turnover.handoff_status}</div>
                                                    <div className="max-w-36 truncate font-mono text-[11px] text-gray-400" title={turnover.handoff_id}>{turnover.handoff_id}</div>
                                                </td>
                                                <td className="px-3 py-3">
                                                    <div className="font-medium text-gray-800">{turnover.cleaning_task_code ?? '—'}</div>
                                                    <div className="text-xs text-gray-500">{humanize(turnover.task_status)}</div>
                                                </td>
                                                <td className="px-3 py-3 text-center tabular-nums text-gray-700">{turnover.attempts}</td>
                                                <td className="whitespace-nowrap px-3 py-3 text-xs text-gray-600">
                                                    <div>{formatDateTime(turnover.delivered_at ?? turnover.failed_at ?? turnover.claimed_at ?? turnover.occurred_at)}</div>
                                                    <div className="mt-0.5 text-gray-400">{formatAge(turnover.last_event_age_seconds)} ago</div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-3 text-right">
                                                    <Link
                                                        href={selectedUrl(turnover.handoff_id)}
                                                        preserveScroll
                                                        className="rounded px-2 py-1 text-sm font-medium text-blue-700 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                    >
                                                        View detail
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex flex-col gap-3 border-t border-gray-200 px-3 py-3 text-sm text-gray-500 sm:flex-row sm:items-center sm:justify-between">
                        <span>{turnovers.total === 0 ? '0 records' : `${turnovers.from}–${turnovers.to} of ${turnovers.total}`}</span>
                        {turnovers.last_page > 1 && (
                            <nav aria-label="Turnover pagination" className="flex flex-wrap gap-1">
                                {turnovers.links.map((link, index) => link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        preserveScroll
                                        aria-current={link.active ? 'page' : undefined}
                                        className={`min-h-9 rounded border px-3 py-1.5 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                                            link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span key={`${link.label}-${index}`} className="min-h-9 rounded border border-gray-200 px-3 py-1.5 text-xs text-gray-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                ))}
                            </nav>
                        )}
                    </div>
                </section>

                <aside aria-label="Selected turnover detail" className="rounded-lg border border-gray-200 bg-white xl:sticky xl:top-4">
                    {!selected_turnover ? (
                        <div className="px-5 py-10 text-center text-sm text-gray-500">
                            Select a turnover to keep its operational detail in view.
                        </div>
                    ) : (
                        <div>
                            <div className="border-b border-gray-200 px-4 py-3">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="font-semibold text-gray-900">Room {selected_turnover.room_number ?? 'Unknown'}</h2>
                                    <StateBadge state={selected_turnover.operational_state} />
                                </div>
                                <p className="mt-1 truncate font-mono text-[11px] text-gray-400" title={selected_turnover.handoff_id}>{selected_turnover.handoff_id}</p>
                            </div>

                            {selected_turnover.operational_state === 'review_required' && (
                                <div role="alert" className="border-b border-red-200 bg-red-50 px-4 py-3 text-xs text-red-900">
                                    Durable evidence requires operational review. Technical corruption detail is intentionally withheld.
                                </div>
                            )}

                            <div className="max-h-[calc(100vh-12rem)] overflow-y-auto px-4 pb-4">
                                <h3 className="pt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Operational status</h3>
                                <dl>
                                    <DetailField label="Handoff status">{selected_turnover.handoff_status}</DetailField>
                                    <DetailField label="Attempts">{selected_turnover.attempts}</DetailField>
                                    <DetailField label="Failure marker">{selected_turnover.safe_last_error_code ?? selected_turnover.review_marker ?? '—'}</DetailField>
                                    <DetailField label="Available">{formatDateTime(selected_turnover.available_at)}</DetailField>
                                    <DetailField label="Claimed">{formatDateTime(selected_turnover.claimed_at)}</DetailField>
                                    <DetailField label="Claim expires">{formatDateTime(selected_turnover.claim_expires_at)}</DetailField>
                                    <DetailField label="Failed">{formatDateTime(selected_turnover.failed_at)}</DetailField>
                                    <DetailField label="Delivered">{formatDateTime(selected_turnover.delivered_at)}</DetailField>
                                </dl>

                                <h3 className="pt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Checkout provenance</h3>
                                <dl>
                                    <DetailField label="Execution"><span className="font-mono">{selected_turnover.checkout_execution_id}</span></DetailField>
                                    <DetailField label="Terminal stay">{selected_turnover.terminal_stay_evidence ? 'Checked out evidence confirmed' : 'Evidence unavailable'}</DetailField>
                                    <DetailField label="Reservation">{selected_turnover.reservation_number ?? '—'}</DetailField>
                                    <DetailField label="Business Date">{selected_turnover.business_date}</DetailField>
                                    <DetailField label="Occurred">{formatDateTime(selected_turnover.occurred_at)}</DetailField>
                                </dl>

                                <h3 className="pt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Housekeeping outcome</h3>
                                <dl>
                                    <DetailField label="Intake"><span className="font-mono">{selected_turnover.intake_id ?? '—'}</span></DetailField>
                                    <DetailField label="Consumer">{selected_turnover.consumer_identity ?? '—'}</DetailField>
                                    <DetailField label="Committed">{selected_turnover.committed ? 'Yes' : 'No'}</DetailField>
                                    <DetailField label="Cleaning task">{selected_turnover.cleaning_task_code ?? '—'} · {humanize(selected_turnover.task_status)}</DetailField>
                                    <DetailField label="Task priority">{humanize(selected_turnover.task_priority)}</DetailField>
                                    <DetailField label="Transition">{humanize(selected_turnover.readiness_transition_type)}</DetailField>
                                    <DetailField label="Readiness">{humanize(selected_turnover.room_readiness_before)} → {humanize(selected_turnover.room_readiness_after ?? selected_turnover.readiness_state)}</DetailField>
                                    <DetailField label="Cleanliness">{humanize(selected_turnover.cleanliness_before)} → {humanize(selected_turnover.cleanliness_after ?? selected_turnover.cleanliness_state)}</DetailField>
                                </dl>

                                <h3 className="pt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Attendant assignment</h3>
                                {assignmentReceipt && (
                                    <div role="status" className="mt-3 rounded border border-green-200 bg-green-50 px-3 py-3 text-xs text-green-950">
                                        <div className="font-semibold">{assignmentReceipt.replayed ? 'Committed result recovered' : 'Assignment committed'}</div>
                                        <div className="mt-1">{assignmentReceipt.assignment_action === 'initial' ? 'Assignment' : 'Reassignment'} {assignmentReceipt.assignment_id}</div>
                                        <div>{assignmentReceipt.user_name} · {assignmentReceipt.department_name} · {formatDateTime(assignmentReceipt.assigned_at)}</div>
                                    </div>
                                )}
                                <dl>
                                    <DetailField label="Current attendant">{selected_turnover.active_assignment?.user_name ?? 'Not assigned'}</DetailField>
                                    <DetailField label="Department">{selected_turnover.active_assignment?.department_name ?? '—'}</DetailField>
                                    <DetailField label="Assigned">{formatDateTime(selected_turnover.active_assignment?.assigned_at ?? null)}</DetailField>
                                    <DetailField label="Task state">{humanize(selected_turnover.task_status)}</DetailField>
                                    <DetailField label="History">
                                        {selected_turnover.assignment_history_summary
                                            ? `${selected_turnover.assignment_history_summary.total} total · ${selected_turnover.assignment_history_summary.reassigned} reassigned`
                                            : '—'}
                                    </DetailField>
                                </dl>

                                {(selected_turnover.assignment_actions?.can_assign || selected_turnover.assignment_actions?.can_reassign) ? (
                                    <div className="mt-3">
                                        {selected_turnover.assignment_actions.can_assign && (
                                            <button type="button" onClick={() => openAssignmentDrawer('assign')} className="min-h-10 w-full rounded bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                                Assign attendant
                                            </button>
                                        )}
                                        {selected_turnover.assignment_actions.can_reassign && (
                                            <button type="button" onClick={() => openAssignmentDrawer('reassign')} className="min-h-10 w-full rounded border border-blue-600 bg-white px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                                Reassign attendant
                                            </button>
                                        )}
                                    </div>
                                ) : selected_turnover.assignment_actions?.assignment_blockers?.length ? (
                                    <div className="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                        {selected_turnover.assignment_actions.assignment_blockers.map(humanize).join(' · ')}
                                    </div>
                                ) : null}

                                {(selected_turnover.links.room || selected_turnover.links.cleaning_task || selected_turnover.links.room_readiness) && (
                                    <div>
                                        <h3 className="pt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Contextual navigation</h3>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {selected_turnover.links.room && <Link href={selected_turnover.links.room} className="rounded border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">Room</Link>}
                                            {selected_turnover.links.cleaning_task && <Link href={selected_turnover.links.cleaning_task} className="rounded border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">Cleaning task</Link>}
                                            {selected_turnover.links.room_readiness && <Link href={selected_turnover.links.room_readiness} className="rounded border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">Room readiness</Link>}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </aside>
            </div>

            {drawerAction && selected_turnover && (
                <div className="fixed inset-0 z-50 flex justify-end bg-gray-950/40" onMouseDown={(event) => {
                    if (event.target === event.currentTarget) closeAssignmentDrawer();
                }}>
                    <section role="dialog" aria-modal="true" aria-labelledby="assignment-drawer-title" className="flex h-full w-full max-w-md flex-col bg-white shadow-2xl">
                        <header className="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                            <div>
                                <h2 id="assignment-drawer-title" className="text-lg font-semibold text-gray-900">
                                    {drawerAction === 'assign' ? 'Assign attendant' : 'Reassign attendant'}
                                </h2>
                                <p className="mt-1 text-sm text-gray-500">Room {selected_turnover.room_number ?? 'Unknown'} · {selected_turnover.cleaning_task_code ?? 'Cleaning task'}</p>
                            </div>
                            <button type="button" onClick={closeAssignmentDrawer} disabled={assignmentSubmitting} aria-label="Close assignment drawer" className="min-h-10 min-w-10 rounded text-xl text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50">×</button>
                        </header>

                        <form onSubmit={submitAssignment} className="flex min-h-0 flex-1 flex-col">
                            <div className="flex-1 overflow-y-auto px-5 py-5">
                                <div className="rounded border border-blue-100 bg-blue-50 px-3 py-3 text-sm text-blue-950">
                                    The selected attendant and Department will be revalidated against the current Property before commit.
                                </div>

                                {drawerAction === 'reassign' && (
                                    <div className="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950">
                                        Current attendant: {selected_turnover.active_assignment?.user_name ?? 'Unknown'}. The prior assignment will be closed as cancelled with a server-owned reassignment reason and retained as immutable history.
                                    </div>
                                )}

                                <label className="mt-5 block">
                                    <span className="text-sm font-semibold text-gray-800">Attendant</span>
                                    <select ref={attendantSelectRef} value={selectedUserId} onChange={(event) => changeAttendant(event.target.value)} disabled={assignmentSubmitting} required className="mt-1 min-h-11 w-full rounded border border-gray-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100">
                                        <option value="">Select an eligible attendant</option>
                                        {(selected_turnover.eligible_attendants ?? []).map((attendant) => (
                                            <option key={attendant.user_id} value={attendant.user_id}>{attendant.display_name} · {attendant.department_name}</option>
                                        ))}
                                    </select>
                                </label>

                                <div className="mt-4 rounded border border-gray-200 bg-gray-50 px-3 py-3">
                                    <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">Authoritative Department</div>
                                    <div className="mt-1 text-sm font-medium text-gray-900">{selectedWorkload?.department_name ?? 'Select an attendant'}</div>
                                </div>

                                {selectedWorkload && (
                                    <div className="mt-4 rounded border border-gray-200 px-3 py-3">
                                        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Current workload</h3>
                                        <dl className="mt-2 grid grid-cols-2 gap-3 text-sm">
                                            <div><dt className="text-gray-500">Active</dt><dd className="font-semibold text-gray-900">{selectedWorkload.active_assignment_count}</dd></div>
                                            <div><dt className="text-gray-500">Credits</dt><dd className="font-semibold text-gray-900">{selectedWorkload.active_credits}</dd></div>
                                            <div><dt className="text-gray-500">Not started</dt><dd className="font-semibold text-gray-900">{selectedWorkload.assigned_not_started_count}</dd></div>
                                            <div><dt className="text-gray-500">In progress</dt><dd className="font-semibold text-gray-900">{selectedWorkload.in_progress_count}</dd></div>
                                            <div><dt className="text-gray-500">Rush</dt><dd className="font-semibold text-gray-900">{selectedWorkload.rush_assignment_count}</dd></div>
                                            <div><dt className="text-gray-500">Oldest active</dt><dd className="font-semibold text-gray-900">{formatDateTime(selectedWorkload.oldest_active_assignment_at)}</dd></div>
                                        </dl>
                                    </div>
                                )}

                                {assignmentError && <div role="alert" className="mt-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{assignmentError}</div>}
                            </div>

                            <footer className="flex gap-3 border-t border-gray-200 px-5 py-4">
                                <button type="button" onClick={closeAssignmentDrawer} disabled={assignmentSubmitting} className="min-h-11 flex-1 rounded border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50">Cancel</button>
                                <button type="submit" disabled={assignmentSubmitting || !selectedUserId} className="min-h-11 flex-1 rounded bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                                    {assignmentSubmitting ? 'Committing…' : drawerAction === 'assign' ? 'Commit assignment' : 'Commit reassignment'}
                                </button>
                            </footer>
                        </form>
                    </section>
                </div>
            )}
        </AppLayout>
    );
}
