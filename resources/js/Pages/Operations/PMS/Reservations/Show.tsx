import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { Reservation } from '@/Types';
import { useState } from 'react';

interface Capabilities {
    can_edit: boolean;
    can_confirm: boolean;
    can_cancel: boolean;
    can_no_show: boolean;
    can_manage_guests: boolean;
    has_operational_evidence: boolean;
}

interface Props {
    reservation: Reservation;
    capabilities: Capabilities;
    guest_options: { id: string; name: string; code: string }[];
    guest_search: string;
}

export default function ReservationShow({ reservation, capabilities, guest_options, guest_search }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [reason, setReason] = useState('');
    const [guestId, setGuestId] = useState('');
    const [guestSearch, setGuestSearch] = useState(guest_search);

    function command(action: 'confirm' | 'cancel' | 'no-show') {
        if (busy) return;
        setBusy(true);
        setError('');
        router.post(`/operations/pms/reservations/${reservation.id}/${action}`,
            action === 'cancel' ? { reason } : {}, {
                preserveScroll: true,
                onError: () => setError('The reservation was not changed. Refresh and review its current eligibility.'),
                onFinish: () => setBusy(false),
            });
    }

    function addGuest() {
        if (!guestId || busy) return;
        setBusy(true);
        router.post(`/operations/pms/reservations/${reservation.id}/guests`, { guest_id: guestId }, {
            onError: () => setError('The guest could not be linked. Review the Property and reservation state.'),
            onFinish: () => setBusy(false),
        });
    }

    const accompanying = (reservation.guests ?? []).filter((guest) => guest.id !== reservation.primary_guest_id);
    const availableGuests = guest_options.filter((guest) =>
        guest.id !== reservation.primary_guest_id && !accompanying.some((linked) => linked.id === guest.id));

    return (
        <AppLayout>
            <div className="mb-6">
                <Link href="/operations/pms/reservations" className="text-sm text-blue-700 dark:text-blue-300">← Reservations</Link>
            </div>
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{reservation.reservation_number}</h1>
                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        Property {reservation.property_id} · {reservation.status.label}
                    </p>
                </div>
                {capabilities.can_edit && (
                    <Link href={`/operations/pms/reservations/${reservation.id}/edit`}
                        className="rounded bg-gray-100 px-4 py-2 text-sm text-gray-800 dark:bg-gray-700 dark:text-white">
                        Edit reservation
                    </Link>
                )}
            </div>

            {error && <div role="alert" className="mb-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">{error}</div>}
            {capabilities.has_operational_evidence && (
                <div className="mb-4 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    Operational or financial evidence exists. Commercial changes are locked. Continue in the controlled Front Desk workspace.
                </div>
            )}
            <section className="mb-6 rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                <h2 className="mb-4 font-semibold text-gray-900 dark:text-white">Reservation details</h2>
                <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt className="text-gray-500">Primary guest</dt><dd>{reservation.primary_guest?.full_name ?? '—'}</dd></div>
                    <div><dt className="text-gray-500">Arrival</dt><dd>{reservation.arrival_date}</dd></div>
                    <div><dt className="text-gray-500">Departure</dt><dd>{reservation.departure_date}</dd></div>
                    <div><dt className="text-gray-500">Nights</dt><dd>{reservation.nights}</dd></div>
                    <div><dt className="text-gray-500">Room type</dt><dd>{reservation.reserved_room_type.label}</dd></div>
                    <div><dt className="text-gray-500">Source</dt><dd>{reservation.reservation_source.label}</dd></div>
                    <div><dt className="text-gray-500">Rate plan</dt><dd>{reservation.rate_plan?.rate_name ?? '—'}</dd></div>
                    <div><dt className="text-gray-500">Guests</dt><dd>{reservation.adults} adults, {reservation.children} children</dd></div>
                </dl>
                {reservation.remarks && <p className="mt-4 border-t pt-4 text-sm text-gray-600 dark:text-gray-300">{reservation.remarks}</p>}
            </section>

            {(capabilities.can_confirm || capabilities.can_cancel || capabilities.can_no_show) && (
                <section className="mb-6 rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                    <h2 className="mb-3 font-semibold text-gray-900 dark:text-white">Reservation actions</h2>
                    <div className="flex flex-wrap items-end gap-3">
                        {capabilities.can_confirm && <button disabled={busy} onClick={() => command('confirm')}
                            className="rounded bg-blue-700 px-4 py-2 text-sm text-white disabled:opacity-50">Confirm</button>}
                        {capabilities.can_no_show && <button disabled={busy} onClick={() => command('no-show')}
                            className="rounded bg-amber-100 px-4 py-2 text-sm text-amber-900 disabled:opacity-50">Mark no-show</button>}
                        {capabilities.can_cancel && <>
                            <label className="text-sm text-gray-700 dark:text-gray-200">Cancellation reason
                                <input value={reason} onChange={(event) => setReason(event.target.value)} maxLength={1000}
                                    className="mt-1 block rounded border border-gray-300 px-3 py-2 dark:bg-gray-700" />
                            </label>
                            <button disabled={busy} onClick={() => command('cancel')}
                                className="rounded bg-red-700 px-4 py-2 text-sm text-white disabled:opacity-50">Cancel</button>
                        </>}
                    </div>
                </section>
            )}

            <section className="mb-6 rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                <h2 className="mb-3 font-semibold text-gray-900 dark:text-white">Accompanying guests</h2>
                {accompanying.length === 0 && <p className="text-sm text-gray-500">No accompanying guests linked.</p>}
                <ul className="space-y-2">
                    {accompanying.map((guest) => <li key={guest.id} className="flex items-center justify-between gap-3 text-sm">
                        <Link href={`/operations/pms/guests/${guest.id}`} className="text-blue-700 dark:text-blue-300">{guest.full_name}</Link>
                        {capabilities.can_manage_guests && <button disabled={busy} className="text-red-700 disabled:opacity-50"
                            onClick={() => router.delete(`/operations/pms/reservations/${reservation.id}/guests/${guest.id}`, {
                                onError: () => setError('The guest relationship was not removed.'),
                            })}>Remove</button>}
                    </li>)}
                </ul>
                {capabilities.can_manage_guests && <div className="mt-4 flex flex-wrap gap-2">
                    <input value={guestSearch} onChange={(event) => setGuestSearch(event.target.value)}
                        aria-label="Search Property guests" placeholder="Name, code or email" maxLength={100}
                        className="rounded border border-gray-300 px-3 py-2 text-sm dark:bg-gray-700" />
                    <button onClick={() => router.get(`/operations/pms/reservations/${reservation.id}`,
                        { guest_search: guestSearch }, { preserveState: true, replace: true })}
                        className="rounded bg-gray-100 px-3 py-2 text-sm text-gray-800 dark:bg-gray-700 dark:text-white">Search</button>
                    <select value={guestId} onChange={(event) => setGuestId(event.target.value)}
                        aria-label="Accompanying guest" className="rounded border border-gray-300 px-3 py-2 text-sm dark:bg-gray-700">
                        <option value="">Select a guest at this Property</option>
                        {availableGuests.map((guest) => <option key={guest.id} value={guest.id}>{guest.name} ({guest.code})</option>)}
                    </select>
                    <button disabled={!guestId || busy} onClick={addGuest}
                        className="rounded bg-blue-700 px-4 py-2 text-sm text-white disabled:opacity-50">Add guest</button>
                    <Link href="/operations/pms/guests" className="px-2 py-2 text-sm text-blue-700 dark:text-blue-300">Find guests</Link>
                </div>}
            </section>
            <div className="rounded-lg bg-blue-50 p-4 text-sm text-blue-900 dark:bg-blue-950 dark:text-blue-100">
                Room assignment, check-in and checkout use the controlled <Link href="/frontdesk/arrivals" className="underline">Front Desk workspace</Link>.
                Legacy PMS stay and folio commands do not establish controlled operational authority.
            </div>
        </AppLayout>
    );
}
