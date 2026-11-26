import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';

interface SecurityEvent {
    id: string;
    event: string;
    outcome?: string;
    actor_user_id: string | null;
    subject_id: string | null;
    reason_code?: string | null;
    occurred_at: string | null;
}

interface EventPage {
    data: SecurityEvent[];
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Props {
    property: string;
    identity_events: EventPage;
    operational_events: EventPage;
    filters: { event_type?: string };
}

function label(value: string): string {
    return value.replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function EventTable({ title, description, page }: { title: string; description: string; page: EventPage }) {
    return (
        <section className="rounded-lg bg-white p-5 shadow" aria-label={title}>
            <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
            <p className="mb-4 mt-1 text-sm text-gray-600">{description}</p>
            {page.data.length === 0 ? (
                <p className="rounded-md border border-gray-200 p-4 text-sm text-gray-600">No events in this Property scope.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead className="bg-gray-50 text-xs uppercase text-gray-600">
                            <tr>
                                <th scope="col" className="px-3 py-2">When</th>
                                <th scope="col" className="px-3 py-2">Event</th>
                                <th scope="col" className="px-3 py-2">Result</th>
                                <th scope="col" className="px-3 py-2">Actor</th>
                                <th scope="col" className="px-3 py-2">Subject</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {page.data.map((event) => (
                                <tr key={event.id}>
                                    <td className="whitespace-nowrap px-3 py-3 text-gray-600">{event.occurred_at ? new Date(event.occurred_at).toLocaleString() : 'Unknown'}</td>
                                    <td className="px-3 py-3 font-medium text-gray-900">
                                        {label(event.event)}
                                        {event.reason_code && <span className="block text-xs font-normal text-gray-500">{label(event.reason_code)}</span>}
                                    </td>
                                    <td className="px-3 py-3 text-gray-600">{event.outcome ?? 'Recorded'}</td>
                                    <td className="px-3 py-3 font-mono text-xs text-gray-600">{event.actor_user_id ?? 'System'}</td>
                                    <td className="px-3 py-3 font-mono text-xs text-gray-600">{event.subject_id ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <div className="mt-4 flex justify-end gap-3 text-sm">
                {page.prev_page_url && <Link href={page.prev_page_url} className="rounded border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50">Previous</Link>}
                {page.next_page_url && <Link href={page.next_page_url} className="rounded border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50">Next</Link>}
            </div>
        </section>
    );
}

export default function SecurityOperationsIndex({ property, identity_events, operational_events, filters }: Props) {
    return (
        <AppLayout>
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Security events</h1>
                <p className="mt-1 text-sm text-gray-600">Read-only evidence for {property}. Events from other Properties are excluded.</p>
            </div>
            <form action="/security/operations" method="get" className="mb-5 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow">
                <label className="flex flex-col gap-1 text-sm font-medium text-gray-700">
                    Identity event type
                    <input name="event_type" defaultValue={filters.event_type ?? ''} maxLength={64} pattern="[A-Z0-9_]*" placeholder="All types" className="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                </label>
                <button type="submit" className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">Apply filter</button>
                <Link href="/security/operations" className="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Clear</Link>
            </form>
            <div className="space-y-5">
                <EventTable title="Identity and session events" description="Events from the immutable identity security ledger. Filter applies to this section." page={identity_events} />
                <EventTable title="Controlled security actions" description="Selected sensitive confirmation, FX role, and FX break-glass actions from the existing audit log. Sensitive payloads are withheld." page={operational_events} />
            </div>
        </AppLayout>
    );
}
