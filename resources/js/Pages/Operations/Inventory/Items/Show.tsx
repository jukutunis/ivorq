import AppLayout from '@/Layouts/AppLayout';
import { InventoryItem, PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { item: InventoryItem; }

export default function ItemShow({ item }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);
    const destroy = () => confirm(`Delete item "${item.name}"?`) && router.delete(`/operations/inventory/items/${item.id}`);
    const label = (value: string) => value.replaceAll('_', ' ');
    return <AppLayout>
        <Link href="/operations/inventory/items" className="text-sm text-gray-500">← Items</Link><div className="flex items-center justify-between my-6"><div><h1 className="text-2xl font-bold">{item.name}</h1><p className="font-mono text-sm text-gray-500 mt-1">{item.sku}</p></div><div className="flex gap-2">{can('inventory.item.edit') && <Link href={`/operations/inventory/items/${item.id}/edit`} className="bg-gray-100 px-4 py-2 rounded text-sm">Edit</Link>}{can('inventory.item.delete') && <button onClick={destroy} className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm">Delete</button>}</div></div>
        <div className="bg-white rounded-lg shadow p-6 grid gap-6 md:grid-cols-4"><Fact label="Category" value={item.category?.name ?? item.category_id} /><Fact label="Inventory type" value={label(item.inventory_type)} /><Fact label="Criticality" value={label(item.criticality)} /><Fact label="Weighted average cost" value={item.weighted_average_cost.toFixed(2)} /><Fact label="Reorder point" value={item.reorder_point.toFixed(4)} /><Fact label="Batch tracked" value={item.is_batch_tracked ? 'Yes' : 'No'} /><Fact label="Expiry tracked" value={item.is_expiry_tracked ? 'Yes' : 'No'} /><Fact label="Status" value={item.is_active ? 'Active' : 'Inactive'} /></div>
        {item.stock_balances && item.stock_balances.length > 0 && <div className="bg-white rounded-lg shadow mt-6 overflow-hidden"><h2 className="px-6 py-4 font-semibold">Stock balances</h2><table className="w-full text-sm"><tbody className="divide-y">{item.stock_balances.map((balance) => <tr key={balance.id}><td className="px-6 py-3">{balance.location?.name ?? balance.location_id}</td><td className="px-6 py-3 text-right font-mono">{balance.quantity}</td></tr>)}</tbody></table></div>}
    </AppLayout>;
}

function Fact({ label, value }: { label: string; value: string }) { return <div><p className="text-xs text-gray-500">{label}</p><p className="text-sm text-gray-800 mt-1 capitalize">{value}</p></div>; }
