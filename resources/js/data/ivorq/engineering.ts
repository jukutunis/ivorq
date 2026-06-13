import { ReactNode } from 'react';

export interface EngineeringSnapshots {
  slaBreached: number;
  openWorkOrders: number;
  pmDueThisWeek: number;
}

export interface AttentionItem {
  id: string;
  title: string;
  meta: string;
  actions: { label: string; isPrimary: boolean }[];
}

export interface QueueTask {
  id: string;
  title: string;
  meta: string;
  badge?: { label: string; status: string };
  badge2?: { label: string; status: string };
  actions?: { label: string; isPrimary: boolean }[];
  style?: React.CSSProperties;
}

export interface EngineeringData {
  snapshots: EngineeringSnapshots;
  attentionItems: AttentionItem[];
  guestImpactWork: QueueTask[];
  backOfHouseWork: QueueTask[];
}

export const engineeringData: EngineeringData = {
  snapshots: {
    slaBreached: 2,
    openWorkOrders: 12,
    pmDueThisWeek: 5,
  },
  attentionItems: [
    {
      id: 'attn-1',
      title: 'AC Compressor — Room 412',
      meta: 'Critical • Overdue by 45 min • Assigned: Wayan Bayu',
      actions: [
        { label: 'Reassign', isPrimary: false },
        { label: 'Escalate', isPrimary: true },
      ],
    },
    {
      id: 'attn-2',
      title: 'Pool Pump Motor — Pool Area',
      meta: 'High • Overdue by 2 hours • Unassigned',
      actions: [
        { label: 'Assign Now', isPrimary: true },
      ],
    },
  ],
  guestImpactWork: [
    {
      id: 'gi-1',
      title: 'Guest Room AC Failure — Room 412',
      meta: 'Guest Complaint • Reported by Front Desk • 10 mins ago',
      badge: { label: 'Unassigned', status: 'critical' },
      actions: [{ label: 'Assign', isPrimary: false }],
      style: { background: 'white' },
    },
    {
      id: 'gi-2',
      title: 'Guest Water Leak — Room 305',
      meta: 'Guest Complaint • Reported by HK • 30 mins ago',
      badge: { label: 'Unassigned', status: 'critical' },
      actions: [{ label: 'Assign', isPrimary: false }],
      style: { background: 'white', borderBottom: 'none' },
    },
  ],
  backOfHouseWork: [
    {
      id: 'boh-1',
      title: 'Pump Maintenance — Pool Plant Room',
      meta: 'Standard PM • Overdue by 2 hours',
      badge: { label: 'Unassigned', status: 'warning' },
      actions: [{ label: 'Assign', isPrimary: false }],
    },
    {
      id: 'boh-2',
      title: 'Generator Inspection — Basement',
      meta: 'Standard PM • Due Today',
      badge: { label: 'Ketut Adi', status: 'inspection' },
      badge2: { label: 'On Track', status: 'ready' },
    },
  ],
};
