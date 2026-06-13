export interface HrisSnapshots {
  clockedIn: { value: string | number };
  lateNoShow: { value: string | number };
  leaveRequests: { value: string | number };
}

export interface LeaveRequest {
  id: string;
  employee: string;
  type: string;
  department: string;
  dates: string;
  duration: string;
}

export interface EmployeeStatus {
  id: string;
  name: string;
  initials: string;
  position: string;
  department: string;
  statusDot: string; // e.g. 'ready-green', 'warning-amber', 'critical-red'
  avatarBg: string; // e.g. 'primary-100', 'warning-amber-bg', 'critical-red-bg'
  avatarColor: string; // e.g. 'primary-500', 'warning-amber', 'critical-red'
  badge: {
    label: string;
    status: string; // 'ready', 'warning', 'critical'
  };
}

export interface HrisData {
  snapshots: HrisSnapshots;
  leaveApprovals: LeaveRequest[];
  workforce: EmployeeStatus[];
}

export const hrisData: HrisData = {
  snapshots: {
    clockedIn: { value: 34 },
    lateNoShow: { value: 2 },
    leaveRequests: { value: 3 },
  },
  leaveApprovals: [
    {
      id: 'lr-1',
      employee: 'Ni Luh Sari',
      type: 'Annual Leave',
      department: 'Housekeeping',
      dates: 'Jun 20 – Jun 24',
      duration: '5 days',
    },
    {
      id: 'lr-2',
      employee: 'Ketut Ari',
      type: 'Sick Leave',
      department: 'Housekeeping',
      dates: 'Jun 14',
      duration: '1 day',
    },
  ],
  workforce: [
    {
      id: 'emp-1',
      name: 'Made Surya',
      initials: 'MS',
      position: 'Duty Manager',
      department: 'Front Office',
      statusDot: 'ready-green',
      avatarBg: 'primary-100',
      avatarColor: 'primary-500',
      badge: { label: 'Clocked In 15:00', status: 'ready' },
    },
    {
      id: 'emp-2',
      name: 'Wayan Darma',
      initials: 'WD',
      position: 'Room Attendant',
      department: 'Housekeeping',
      statusDot: 'warning-amber',
      avatarBg: 'warning-amber-bg',
      avatarColor: 'warning-amber',
      badge: { label: 'Late — Not Clocked In', status: 'warning' },
    },
    {
      id: 'emp-3',
      name: 'Ketut Arini',
      initials: 'KA',
      position: 'Room Attendant',
      department: 'Housekeeping',
      statusDot: 'critical-red',
      avatarBg: 'critical-red-bg',
      avatarColor: 'critical-red',
      badge: { label: 'Absent — Sick Leave', status: 'critical' },
    },
  ],
};
