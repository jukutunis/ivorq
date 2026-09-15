import AppLayout from '@/Layouts/AppLayout';
import { InventoryUnit, PageProps } from '@/Types';
import { Link, router, usePage } from '@inertiajs/react';

interface Props { unit: InventoryUnit; }

export default function UnitShow({ unit }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user?.is_super_admin || (auth.permissions ?? []).includes(permission);
    const destroy = () => confirm(`Delete unit "${unit.name}"?`) && router.delete(`/operations/inventory/units/${unit.id}`);
    return <AppLayout>
        <Link href="/operations/inventory/units" className="text-sm text-gray-500">← Units</Link>
        <div className="flex items-center justify-between my-6"><h1 className="text-2xl font-bold">{unit.name}</h1><div className="flex gap-2">{can('inventory.unit.edit') && <Link href={`/operations/inventory/units/${unit.id}/edit`} className="bg-gray-100 px-4 py-2 rounded text-sm">Edit</Link>}{can('inventory.unit.delete') && <button onClick={destroy} className="bg-red-50 text-red-600 px-4 py-2 rounded text-sm">Delete</button>}</div></div>
        <div className="bg-white rounded-lg shadow p-6"><p className="text-xs text-gray-500">Code</p><p className="text-sm font-mono mt-1">{unit.code}</p></div>
    </AppLayout>;
}
