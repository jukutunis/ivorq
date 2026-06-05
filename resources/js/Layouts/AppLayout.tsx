import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { PageProps } from '@/Types';

function SectionLabel({ label }: { label: string }) {
    return (
        <p className="px-3 pt-5 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">
            {label}
        </p>
    );
}

function NavItem({ href, label, active }: { href: string; label: string; active: boolean }) {
    return (
        <Link
            href={href}
            className={`block px-3 py-1.5 rounded text-sm transition-colors ${
                active
                    ? 'bg-blue-50 text-blue-700 font-medium'
                    : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
            }`}
        >
            {label}
        </Link>
    );
}

function SubNavItem({ href, label, active }: { href: string; label: string; active: boolean }) {
    return (
        <Link
            href={href}
            className={`block pl-5 pr-3 py-1.5 rounded text-sm transition-colors ${
                active
                    ? 'text-blue-700 font-medium'
                    : 'text-gray-500 hover:text-gray-800 hover:bg-gray-50'
            }`}
        >
            {label}
        </Link>
    );
}

export default function AppLayout({ children }: PropsWithChildren) {
    const { auth, flash } = usePage<PageProps>().props;
    const currentUrl = usePage().url;

    const is     = (path: string) => currentUrl === path;
    const starts = (path: string) => currentUrl.startsWith(path);

    const permissions: string[] = auth.permissions ?? [];
    const can = (permission: string): boolean =>
        auth.user?.is_super_admin === true || permissions.includes(permission);

    // Housekeeping section visible if user has any housekeeping view permission
    const showHousekeeping =
        can('housekeeping.room.view') ||
        can('housekeeping.task.view') ||
        can('housekeeping.inspection.view') ||
        can('housekeeping.checklist.view');

    // PMS section visible if user has any PMS view permission
    const showPms =
        can('pms.guest.view') ||
        can('pms.reservation.view') ||
        can('pms.room-block.view') ||
        can('pms.folio.view') ||
        can('pms.rate-plan.view');

    return (
        <div className="min-h-screen bg-gray-50 flex">

            {/* ── Sidebar ─────────────────────────────────────────────── */}
            <aside className="hidden md:flex w-56 bg-white border-r border-gray-200 flex-shrink-0 flex-col">
                {/* Brand */}
                <div className="px-4 py-4 border-b border-gray-200">
                    <Link href="/dashboard" className="font-bold text-gray-900 text-base tracking-tight">
                        IVORQ
                    </Link>
                </div>

                {/* Navigation */}
                <nav className="flex-1 px-2 py-2 overflow-y-auto">

                    {/* Foundation */}
                    <SectionLabel label="Foundation" />
                    <NavItem href="/dashboard"   label="Dashboard"    active={is('/dashboard')} />
                    {can('property.view')    && <NavItem href="/properties"  label="Properties"   active={starts('/properties')} />}
                    {can('property.view')    && <NavItem href="/companies"   label="Companies"    active={starts('/companies')} />}
                    {can('department.view')  && <NavItem href="/departments" label="Departments"  active={starts('/departments')} />}
                    {can('user.view')        && <NavItem href="/users"       label="Users"        active={starts('/users')} />}
                    {can('role.view')        && <NavItem href="/roles"       label="Roles"        active={starts('/roles')} />}
                    {can('audit.view')       && <NavItem href="/audit"       label="Audit Log"    active={starts('/audit')} />}
                    {can('activity.view')    && <NavItem href="/activity"    label="Activity Log" active={starts('/activity')} />}

                    {/* Housekeeping */}
                    {showHousekeeping && (
                        <>
                            <SectionLabel label="Housekeeping" />
                            <NavItem
                                href="/operations/housekeeping"
                                label="Dashboard"
                                active={is('/operations/housekeeping')}
                            />
                            {can('housekeeping.room.view')       && <NavItem href="/operations/rooms"          label="Rooms"       active={starts('/operations/rooms')} />}
                            {can('housekeeping.task.view')       && <NavItem href="/operations/cleaning-tasks" label="Tasks"       active={starts('/operations/cleaning-tasks')} />}
                            {can('housekeeping.inspection.view') && <NavItem href="/operations/inspections"    label="Inspections" active={starts('/operations/inspections')} />}
                            {can('housekeeping.checklist.view')  && <NavItem href="/operations/checklists"     label="Checklists"  active={starts('/operations/checklists')} />}
                        </>
                    )}

                    {/* PMS */}
                    {showPms && (
                        <>
                            <SectionLabel label="PMS" />
                            <NavItem
                                href="/operations/pms"
                                label="Dashboard"
                                active={is('/operations/pms')}
                            />
                            {can('pms.guest.view')      && <SubNavItem href="/operations/pms/guests"       label="Guests"       active={starts('/operations/pms/guests')} />}
                            {can('pms.reservation.view') && <SubNavItem href="/operations/pms/reservations" label="Reservations" active={starts('/operations/pms/reservations')} />}
                            {can('pms.room-block.view')  && <SubNavItem href="/operations/pms/room-blocks"  label="Room Blocks"  active={starts('/operations/pms/room-blocks')} />}
                            {can('pms.folio.view')       && <SubNavItem href="/operations/pms/folios"       label="Folios"       active={starts('/operations/pms/folios')} />}
                            {can('pms.rate-plan.view')   && <SubNavItem href="/operations/pms/rate-plans"   label="Rate Plans"   active={starts('/operations/pms/rate-plans')} />}
                        </>
                    )}

                </nav>
            </aside>

            {/* ── Main area ────────────────────────────────────────────── */}
            <div className="flex-1 flex flex-col min-h-screen overflow-hidden">

                {/* Top bar */}
                <header className="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-end flex-shrink-0">
                    <div className="flex items-center gap-4 text-sm">
                        <Link href="/profile" className="text-gray-600 hover:text-gray-900">
                            {auth.user?.name}
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="text-gray-500 hover:text-gray-900"
                        >
                            Logout
                        </Link>
                    </div>
                </header>

                {/* Flash messages */}
                {flash?.success && (
                    <div className="bg-green-50 border-b border-green-200 px-6 py-2 text-sm text-green-700 flex-shrink-0">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="bg-red-50 border-b border-red-200 px-6 py-2 text-sm text-red-700 flex-shrink-0">
                        {flash.error}
                    </div>
                )}

                {/* Content */}
                <main className="flex-1 px-6 py-8 max-w-7xl w-full">
                    {children}
                </main>

            </div>
        </div>
    );
}
