import React from 'react';
import { Head } from '@inertiajs/react';

interface EngineeringWorkspaceProps {
    dashboard: any;
    myTasks: any;
    myAreas: any;
}

export default function EngineeringWorkspace({ dashboard, myTasks, myAreas }: EngineeringWorkspaceProps) {
    return (
        <div className="min-h-screen bg-slate-900 text-slate-100 p-6">
            <Head title="Engineering Workspace - Command Center" />
            
            <header className="mb-8 flex items-center justify-between">
                <div>
                    <h1 className="text-3xl font-bold font-inter text-slate-50">Engineering Command Center</h1>
                    <p className="text-slate-400 mt-1">Property Overview & Action Queues</p>
                </div>
                <div className="flex gap-4">
                    <button className="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg font-medium shadow-sm transition">
                        + Quick Create
                    </button>
                    <button className="bg-slate-800 border border-slate-700 text-slate-300 hover:text-white hover:bg-slate-700 px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
                        <span>Universal Search</span>
                        <kbd className="bg-slate-900 border border-slate-600 px-2 rounded text-xs">⌘K</kbd>
                    </button>
                </div>
            </header>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                {/* KPI Cards */}
                <div className="bg-slate-800 rounded-xl p-6 border border-slate-700/50 shadow-sm relative overflow-hidden group">
                    <div className="absolute top-0 right-0 p-4 opacity-10">
                        <svg className="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    </div>
                    <h3 className="text-slate-400 font-medium mb-1">Open Work Orders</h3>
                    <p className="text-4xl font-bold text-slate-50">{dashboard?.open_work_orders || 0}</p>
                    <div className="mt-4 flex items-center gap-2 text-sm text-emerald-400">
                        <span>↑ 2% vs last week</span>
                    </div>
                </div>

                <div className="bg-slate-800 rounded-xl p-6 border border-slate-700/50 shadow-sm relative overflow-hidden group">
                    <h3 className="text-slate-400 font-medium mb-1">PM Compliance</h3>
                    <p className="text-4xl font-bold text-slate-50">{dashboard?.pm_compliance || 0}%</p>
                    <div className="mt-4 flex items-center gap-2 text-sm text-amber-400">
                        <span>Target: 98%</span>
                    </div>
                </div>

                <div className="bg-slate-800 rounded-xl p-6 border border-slate-700/50 shadow-sm relative overflow-hidden group">
                    <h3 className="text-slate-400 font-medium mb-1">Critical Incidents</h3>
                    <p className="text-4xl font-bold text-rose-500">{dashboard?.critical_incidents || 0}</p>
                    <div className="mt-4 flex items-center gap-2 text-sm text-rose-400">
                        <span className="flex h-2 w-2 relative">
                            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                            <span className="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                        </span>
                        <span>Requires immediate action</span>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                {/* Main Content Area */}
                <div className="lg:col-span-8 space-y-6">
                    <div className="bg-slate-800 rounded-xl border border-slate-700/50 shadow-sm">
                        <div className="border-b border-slate-700 p-4 flex justify-between items-center">
                            <h2 className="text-lg font-semibold text-slate-100">My Tasks</h2>
                            <button className="text-sm text-blue-400 hover:text-blue-300">View All</button>
                        </div>
                        <div className="p-4">
                            {myTasks?.assigned_work_orders?.length > 0 ? (
                                <ul className="space-y-3">
                                    {myTasks.assigned_work_orders.map((wo: any) => (
                                        <li key={wo.id} className="flex justify-between items-center p-4 bg-slate-800/50 rounded-lg border border-slate-700 hover:border-slate-600 transition">
                                            <div>
                                                <p className="font-medium text-slate-200">{wo.wo_number}</p>
                                                <p className="text-sm text-slate-400 mt-1">{wo.title || 'General Maintenance'}</p>
                                            </div>
                                            <button className="bg-slate-700 hover:bg-slate-600 px-3 py-1.5 rounded-md text-sm transition">Execute</button>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <div className="text-center py-8 text-slate-500">No active tasks assigned to your area.</div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Right Sidebar */}
                <div className="lg:col-span-4 space-y-6">
                    <div className="bg-slate-800 rounded-xl border border-slate-700/50 shadow-sm p-4">
                        <h2 className="text-lg font-semibold text-slate-100 mb-4">Shift Handover</h2>
                        <div className="space-y-3">
                            <div className="p-3 bg-amber-500/10 border border-amber-500/20 rounded-lg text-amber-200 text-sm">
                                <strong>Pending:</strong> Night shift reported issues with Chiller #2. Requires supervisor acknowledgement.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Mobile FAB */}
            <div className="fixed bottom-6 right-6 md:hidden">
                <button className="bg-blue-600 hover:bg-blue-500 text-white w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition">
                    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4"/></svg>
                </button>
            </div>
        </div>
    );
}
