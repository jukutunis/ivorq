import { ReactNode } from 'react';

export interface HousekeepingSnapshots {
  dirty: number;
  pendingInspection: number;
  cleanReady: number;
  rushVip: number;
}

export interface WorkCardAction {
  label: string;
  isPrimary: boolean;
}

export interface WorkCardData {
  id: string;
  borderColor: string;
  meta: ReactNode[];
  title: string;
  detail?: string;
  actions?: WorkCardAction[];
}

export interface FloorGroup {
  floor: string;
  cards: WorkCardData[];
}

export interface BoardColumnData {
  id: string;
  title: string;
  count: number;
  floorGroups: FloorGroup[];
}

export interface HousekeepingData {
  snapshots: HousekeepingSnapshots;
  columns: BoardColumnData[];
}

export const housekeepingData: HousekeepingData = {
  snapshots: {
    dirty: 38,
    pendingInspection: 15,
    cleanReady: 120,
    rushVip: 8,
  },
  columns: [
    {
      id: 'dirty',
      title: 'Dirty / Unassigned',
      count: 12,
      floorGroups: [
        {
          floor: 'Floor 3',
          cards: [
            {
              id: 'wc-1',
              borderColor: 'warning-amber',
              meta: ['Standard'],
              title: 'Room 301 — Superior',
              detail: 'Checkout 12:00 • No arrival today',
              actions: [{ label: 'Assign Task', isPrimary: false }],
            },
          ],
        },
        {
          floor: 'Floor 4',
          cards: [
            {
              id: 'wc-2',
              borderColor: 'vip-gold',
              meta: [], // The badge is added dynamically in the component
              title: 'Room 412 — Suite',
              detail: 'Checkout 11:00 • VIP arriving 14:00',
              actions: [{ label: 'Assign Task', isPrimary: true }],
            },
          ],
        },
      ],
    },
    {
      id: 'in-progress',
      title: 'In Progress',
      count: 5,
      floorGroups: [
        {
          floor: 'Floor 2',
          cards: [
            {
              id: 'wc-3',
              borderColor: 'inspection-blue',
              meta: ['Wayan Darma'],
              title: 'Room 208 — Twin',
              detail: 'Started 35 min ago',
            },
          ],
        },
        {
          floor: 'Floor 3',
          cards: [
            {
              id: 'wc-4',
              borderColor: 'inspection-blue',
              meta: ['Ni Luh Sari'],
              title: 'Room 305 — Deluxe',
              detail: 'Started 15 min ago',
            },
          ],
        },
      ],
    },
    {
      id: 'pending-inspection',
      title: 'Pending Inspection',
      count: 3,
      floorGroups: [
        {
          floor: 'Floor 2',
          cards: [
            {
              id: 'wc-5',
              borderColor: 'warning-amber',
              meta: ['Cleaned by: Ketut'],
              title: 'Room 201 — Superior',
              actions: [{ label: 'Inspect', isPrimary: true }],
            },
          ],
        },
      ],
    },
    {
      id: 'clean-ready',
      title: 'Clean / Ready',
      count: 120,
      floorGroups: [
        {
          floor: '',
          cards: [
            {
              id: 'wc-6',
              borderColor: 'ready-green',
              meta: ['Inspected', 'Floor 6'],
              title: 'Room 601 — Deluxe King',
              detail: 'VIP Chen arriving 16:00',
            },
          ],
        },
      ],
    },
  ],
};
