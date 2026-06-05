import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { Folio, PaginatedData, EnumOption } from '@/Types';

interface Props {
    folios: PaginatedData<Folio>;
}

function folioStatusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        open:   'bg-green-100 text-green-700',
        closed: 'bg-gray-100 text-gray-600',
        void:   'bg-red-100 text-red-700',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

export default function FolioIndex({ folios }: Props) {
    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Folios</h1>
                    <p className="text-sm text-gray-500 mt-1">{folios.total} folio{folios.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href="/operations/pms" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Dashboard
                    </Link>
                    <Link href="/operations/pms/reservations" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Reservations
                    </Link>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {folios.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No folios found. Folios are created from a reservation.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Folio #</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Reservation</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Charges</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {folios.data.map((folio) => (
                                <tr key={folio.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 font-mono text-xs text-gray-600">{folio.folio_number}</td>
                                    <td className="px-6 py-4 text-gray-600">
                                        {folio.reservation ? (
                                            <Link href={`/operations/pms/reservations/${folio.reservation.id}`} className="text-blue-600 hover:text-blue-800 font-mono text-xs">
                                                {folio.reservation.reservation_number}
                                            </Link>
                                        ) : <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4 text-gray-700">
                                        {folio.guest?.full_name ?? <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4">{folioStatusBadge(folio.status)}</td>
                                    <td className="px-6 py-4 text-gray-700">{folio.currency} {folio.total_charges.toFixed(2)}</td>
                                    <td className="px-6 py-4 font-medium text-gray-900">{folio.currency} {folio.balance.toFixed(2)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={`/operations/pms/folios/${folio.id}`} className="text-blue-600 hover:text-blue-800 text-sm">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                )}

                {folios.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {folios.current_page} of {folios.last_page}</span>
                        <div className="flex gap-1">
                            {folios.links.map((link, i) => (
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1 rounded border text-xs ${link.active ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span key={i} className="px-3 py-1 rounded border border-gray-200 text-xs text-gray-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
