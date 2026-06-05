import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { RoomBlock, PaginatedData, EnumOption } from '@/Types';

interface Props {
    room_blocks: PaginatedData<RoomBlock>;
    block_types: EnumOption[];
    filters:     { block_type?: string; status?: string };
}

function blockStatusBadge(status: EnumOption) {
    const classes: Record<string, string> = {
        active:   'bg-yellow-100 text-yellow-700',
        released: 'bg-green-100 text-green-700',
        expired:  'bg-gray-100 text-gray-600',
    };
    const cls = classes[String(status.value)] ?? 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
            {status.label}
        </span>
    );
}

export default function RoomBlockIndex({ room_blocks, block_types, filters }: Props) {
    function applyFilter(field: string, value: string) {
        router.get('/operations/pms/room-blocks', { ...filters, [field]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Room Blocks</h1>
                    <p className="text-sm text-gray-500 mt-1">{room_blocks.total} block{room_blocks.total !== 1 ? 's' : ''} total</p>
                </div>
                <div className="flex gap-2">
                    <Link href="/operations/pms" className="bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-200">
                        Dashboard
                    </Link>
                    <Link href="/operations/pms/room-blocks/create" className="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
                        New Block
                    </Link>
                </div>
            </div>

            {/* Filters */}
            <div className="flex gap-3 mb-4">
                <select
                    value={filters.block_type ?? ''}
                    onChange={(e) => applyFilter('block_type', e.target.value)}
                    className="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="">All Block Types</option>
                    {block_types.map((t) => (
                        <option key={String(t.value)} value={String(t.value)}>{t.label}</option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-lg shadow overflow-hidden">
                {room_blocks.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-gray-400 text-sm">
                        No room blocks found.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Start</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">End</th>
                                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th className="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {room_blocks.data.map((block) => (
                                <tr key={block.id} className="hover:bg-gray-50">
                                    <td className="px-6 py-4 font-medium text-gray-900">
                                        {block.room?.room_number ?? <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="px-6 py-4 text-gray-600">{block.block_type.label}</td>
                                    <td className="px-6 py-4 text-gray-600">{block.reason?.label ?? <span className="text-gray-400">—</span>}</td>
                                    <td className="px-6 py-4 text-gray-600 text-xs">{block.start_at}</td>
                                    <td className="px-6 py-4 text-gray-600 text-xs">{block.end_at ?? '—'}</td>
                                    <td className="px-6 py-4">{blockStatusBadge(block.status)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={`/operations/pms/room-blocks/${block.id}`} className="text-blue-600 hover:text-blue-800 text-sm">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                {room_blocks.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                        <span>Page {room_blocks.current_page} of {room_blocks.last_page}</span>
                        <div className="flex gap-1">
                            {room_blocks.links.map((link, i) => (
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
