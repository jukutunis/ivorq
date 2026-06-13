import { ReactNode } from 'react';

export interface SnapshotTrend {
  label: string;
  isPositive: boolean;
  color?: string; // e.g. 'ready-green', 'critical-red'
}

export interface FinanceSnapshots {
  revenueToday: { value: string; trend: SnapshotTrend };
  cashPosition: { value: string; trend: SnapshotTrend };
  apPending: { value: string };
  arOverdue: { value: string };
}

export interface BudgetWatchItem {
  id: string;
  title: string;
  percent: number;
  trend?: SnapshotTrend;
  color: string; // e.g. 'critical-red', 'ready-green', 'warning-amber'
}

export interface KanbanCardData {
  id: string;
  borderColor: string; // e.g. 'warning-amber', 'critical-red'
  metaLeft: string;
  metaRight: string;
  title: string;
  detail: string;
  detailColor?: string; // e.g. 'critical-red'
  actions: { label: string; isPrimary: boolean }[];
}

export interface FinanceData {
  snapshots: FinanceSnapshots;
  budgetWatch: BudgetWatchItem[];
  apPendingApproval: KanbanCardData[];
  arFollowUp: KanbanCardData[];
}

export const financeData: FinanceData = {
  snapshots: {
    revenueToday: {
      value: '$14,250',
      trend: { label: '▲ +12%', isPositive: true, color: 'ready-green' },
    },
    cashPosition: {
      value: '$45,800',
      trend: { label: '▼ -8%', isPositive: false, color: 'critical-red' },
    },
    apPending: { value: '$8,400' },
    arOverdue: { value: '$12,000' },
  },
  budgetWatch: [
    {
      id: 'bw-1',
      title: 'Payroll',
      percent: 103,
      trend: { label: '▲ +12%', isPositive: false },
      color: 'critical-red',
    },
    {
      id: 'bw-2',
      title: 'Rooms Revenue',
      percent: 82,
      trend: { label: '▼ -8%', isPositive: false },
      color: 'ready-green',
    },
    {
      id: 'bw-3',
      title: 'Utilities',
      percent: 61,
      color: 'warning-amber',
    },
  ],
  apPendingApproval: [
    {
      id: 'ap-1',
      borderColor: 'warning-amber',
      metaLeft: 'Linen Supply Co.',
      metaRight: '$1,200.00',
      title: 'Invoice #LS-2045',
      detail: 'Due: Today',
      actions: [{ label: 'Authorize', isPrimary: true }],
    },
    {
      id: 'ap-2',
      borderColor: 'critical-red',
      metaLeft: 'Fresh Foods Ltd.',
      metaRight: '$2,450.00',
      title: 'Invoice #FF-1892',
      detail: 'Overdue: 3 days',
      detailColor: 'critical-red',
      actions: [{ label: 'Authorize', isPrimary: true }],
    },
  ],
  arFollowUp: [
    {
      id: 'ar-1',
      borderColor: 'critical-red',
      metaLeft: 'Corporate Account',
      metaRight: '$5,600.00',
      title: 'TechCorp Retreat',
      detail: 'Overdue: 15 days',
      actions: [{ label: 'Send Reminder', isPrimary: false }],
    },
  ],
};
