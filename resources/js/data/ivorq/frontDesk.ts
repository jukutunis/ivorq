export interface SnapshotData {
  totalArrivals: number;
  vipDueIn: number;
  noRoomAssigned: number;
  roomsNotReady: number;
}

export interface AttentionItem {
  id: string;
  type: 'vip' | 'early' | 'payment';
  title: string;
  badge?: string;
  meta: string;
  actions: string[];
}

export interface QueueItemData {
  id: string;
  name: string;
  vip: boolean;
  roomType: string;
  roomStatus: string;
  roomStatusReady: boolean;
  roomStatusWarning: boolean;
  roomStatusCritical: boolean;
  reservationId: string;
  actions: string[];
}

export const frontDeskData = {
  snapshots: {
    totalArrivals: 24,
    vipDueIn: 3,
    noRoomAssigned: 2,
    roomsNotReady: 4,
  } as SnapshotData,

  attentionItems: [
    {
      id: 'a1',
      type: 'vip',
      title: 'No Room Assigned',
      badge: 'VIP',
      meta: 'Smith, James • RES-9981 • Suite • ETA 14:00',
      actions: ['Assign Room'],
    },
    {
      id: 'a2',
      type: 'early',
      title: 'Early Arrival — Room Not Ready',
      meta: 'Tanaka, Yuki • RES-9983 • Arrived 10:00 (Room 305 Dirty)',
      actions: ['Rush Housekeeping', 'Reassign Room'],
    },
    {
      id: 'a3',
      type: 'payment',
      title: 'Missing Payment Guarantee',
      meta: 'Williams, Sarah • RES-9984 • Deluxe King • ETA 16:00',
      actions: ['Send Payment Link'],
    },
  ] as AttentionItem[],

  checkInQueue: [
    {
      id: 'q1',
      name: 'Anderson, James',
      vip: true,
      roomType: 'King Suite',
      roomStatus: 'Room 412 Ready',
      roomStatusReady: true,
      roomStatusWarning: false,
      roomStatusCritical: false,
      reservationId: 'RES-9981',
      actions: ['Start Check-In'],
    },
    {
      id: 'q2',
      name: 'Mueller, Hans',
      vip: false,
      roomType: 'Deluxe King',
      roomStatus: 'Room 508 Late Check-Out',
      roomStatusReady: false,
      roomStatusWarning: true,
      roomStatusCritical: false,
      reservationId: 'RES-9985',
      actions: ['Resolve Conflict'],
    },
    {
      id: 'q3',
      name: 'Chen, Wei Lin',
      vip: false,
      roomType: 'Superior Twin',
      roomStatus: 'No Room Assigned',
      roomStatusReady: false,
      roomStatusWarning: false,
      roomStatusCritical: true,
      reservationId: 'RES-9986',
      actions: ['Assign Room'],
    },
  ] as QueueItemData[],
};
