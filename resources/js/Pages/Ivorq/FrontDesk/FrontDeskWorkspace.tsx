import React from 'react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';
import QueueList from '../../../Components/Ivorq/patterns/QueueList';
import QueueItem from '../../../Components/Ivorq/patterns/QueueItem';
import Button from '../../../Components/Ivorq/primitives/Button';
import StatusBadge from '../../../Components/Ivorq/primitives/StatusBadge';
import Icon from '../../../Components/Ivorq/primitives/Icon';

type ArrivalRow = {
  reservation_id: string;
  reservation_number: string;
  reservation_status: string;
  arrival_date: string | null;
  departure_date: string | null;
  guest: { id: string; name: string; vip_level: number | null } | null;
  room_type: string | null;
  assigned_room: { id: string; number: string; room_type: string | null } | null;
  housekeeping: {
    state: string;
    cleanliness_status: string | null;
    readiness_state: string | null;
    source: string;
  };
  engineering: {
    state: string;
    source: string;
    active_block_count: number;
  };
  eligibility: {
    eligible: boolean;
    state: string;
    blockers: string[];
  };
  source_requirements: {
    guest_registration: string;
    identity_document: string;
  };
};

type ArrivalWorkspace = {
  property: { id: string; name: string; company_id: string };
  businessRuleDate: string;
  filters: { search: string; arrival_date: string };
  policy: {
    guestRegistrationRequirement: string;
    identityDocumentRequirement: string;
  };
  snapshots: {
    totalArrivals: number;
    arrivalReady: number;
    blockedArrivals: number;
    unassignedEligible: number;
    assignedReadyToCheckIn: number;
  };
  views: {
    arrivingToday: ArrivalRow[];
    expectedArrivals: ArrivalRow[];
    blockedArrivals: ArrivalRow[];
    unassignedEligibleArrivals: ArrivalRow[];
    assignedReadyToCheckIn: ArrivalRow[];
  };
  financeMarker: string;
};

type Props = {
  activeTab?: string;
  arrivalWorkspace?: ArrivalWorkspace;
};

const emptyWorkspace: ArrivalWorkspace = {
  property: { id: '', name: 'Active Property', company_id: '' },
  businessRuleDate: '',
  filters: { search: '', arrival_date: '' },
  policy: {
    guestRegistrationRequirement: 'Not configured by canonical source.',
    identityDocumentRequirement: 'Not configured by canonical source.',
  },
  snapshots: {
    totalArrivals: 0,
    arrivalReady: 0,
    blockedArrivals: 0,
    unassignedEligible: 0,
    assignedReadyToCheckIn: 0,
  },
  views: {
    arrivingToday: [],
    expectedArrivals: [],
    blockedArrivals: [],
    unassignedEligibleArrivals: [],
    assignedReadyToCheckIn: [],
  },
  financeMarker: 'Financial settlement: Not evaluated in Front Desk Package A.',
};

const FrontDeskWorkspace = ({ activeTab = 'arrivals', arrivalWorkspace = emptyWorkspace }: Props) => {
  const tabs = [
    { href: '/frontdesk/arrivals', label: 'Arrivals', badge: arrivalWorkspace.snapshots.totalArrivals },
    { href: '/frontdesk/departures', label: 'Departures' },
    { href: '/frontdesk/in-house', label: 'In House' },
    { href: '/frontdesk/room-readiness', label: 'Room Readiness' },
    { href: '/frontdesk/reservation-board', label: 'Reservation Board' },
  ];

  return (
    <div className="workspace">
      <WorkspaceHeader title="Arrival Queue">
        <Button variant="secondary">
          <Icon name="refresh" /> Refresh
        </Button>
      </WorkspaceHeader>

      <ModuleTabs tabs={tabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <form method="get" action="/frontdesk/arrivals">
            <div className="filter-group">
              <label className="filter-label">Search</label>
              <input
                type="text"
                name="search"
                className="filter-input"
                placeholder="Guest or reservation"
                defaultValue={arrivalWorkspace.filters.search}
              />
            </div>
            <div className="filter-group">
              <label className="filter-label">Arrival Date</label>
              <input
                type="date"
                name="arrival_date"
                className="filter-input"
                defaultValue={arrivalWorkspace.filters.arrival_date}
              />
            </div>
            <Button variant="secondary" size="sm">
              <Icon name="search" /> Apply
            </Button>
          </form>

          <div className="filter-group">
            <label className="filter-label">Guest Registration</label>
            <div className="filter-hint">{arrivalWorkspace.policy.guestRegistrationRequirement}</div>
          </div>
          <div className="filter-group">
            <label className="filter-label">Identity Document</label>
            <div className="filter-hint">{arrivalWorkspace.policy.identityDocumentRequirement}</div>
          </div>
          <div className="filter-group">
            <label className="filter-label">Financial Settlement</label>
            <div className="filter-hint">{arrivalWorkspace.financeMarker.replace('Financial settlement: ', '')}</div>
          </div>
        </QuickFilterPanel>

        <MainContent>
          <OperationalSnapshot>
            <SnapshotCard value={arrivalWorkspace.snapshots.totalArrivals} label="Arriving Today" />
            <SnapshotCard value={arrivalWorkspace.snapshots.arrivalReady} label="Arrival Ready" statusColor="ready-green" />
            <SnapshotCard value={arrivalWorkspace.snapshots.blockedArrivals} label="Blocked Arrivals" statusColor="warning-amber" />
            <SnapshotCard value={arrivalWorkspace.snapshots.unassignedEligible} label="Unassigned Eligible" />
            <SnapshotCard value={arrivalWorkspace.snapshots.assignedReadyToCheckIn} label="Assigned Ready" statusColor="ready-green" />
          </OperationalSnapshot>

          <ArrivalQueue title="Arriving Today" rows={arrivalWorkspace.views.arrivingToday} />
          <ArrivalQueue title="Expected Arrivals" rows={arrivalWorkspace.views.expectedArrivals} />
          <ArrivalQueue title="Blocked Arrivals" rows={arrivalWorkspace.views.blockedArrivals} />
          <ArrivalQueue title="Unassigned Eligible Arrivals" rows={arrivalWorkspace.views.unassignedEligibleArrivals} />
          <ArrivalQueue title="Assigned / Ready-to-Check-In" rows={arrivalWorkspace.views.assignedReadyToCheckIn} />
        </MainContent>
      </SplitLayout>
    </div>
  );
};

function ArrivalQueue({ title, rows }: { title: string; rows: ArrivalRow[] }) {
  return (
    <QueueList title={title} count={rows.length}>
      {rows.length === 0 ? (
        <QueueItem title="No source-proven arrivals" meta="No reservation evidence matched this view." />
      ) : (
        rows.map((row) => (
          <QueueItem
            key={`${title}-${row.reservation_id}`}
            title={
              <>
                {row.guest?.name ?? 'Guest linkage missing'}
                {row.guest?.vip_level ? (
                  <StatusBadge status="vip" style={{ fontSize: '11px', padding: '2px 6px', marginLeft: '6px' }}>
                    VIP {row.guest.vip_level}
                  </StatusBadge>
                ) : null}
              </>
            }
            meta={<ArrivalMeta row={row} />}
            actions={<EligibilityBadge row={row} />}
          />
        ))
      )}
    </QueueList>
  );
}

function ArrivalMeta({ row }: { row: ArrivalRow }) {
  const room = row.assigned_room ? `Room ${row.assigned_room.number}` : 'No room assigned';
  const blockers = row.eligibility.blockers.length > 0 ? row.eligibility.blockers.join(' | ') : 'No operational blocker';

  return (
    <>
      <span style={{ color: 'var(--text-primary)', fontWeight: 600 }}>{row.reservation_number}</span>{' '}
      <span>Arrival {row.arrival_date ?? 'Not configured'}</span>{' '}
      <span>Room type {row.room_type ?? 'Not configured'}</span>{' '}
      <span>{room}</span>{' '}
      <span>Housekeeping {row.housekeeping.readiness_state ?? row.housekeeping.state}</span>{' '}
      <span>Engineering {row.engineering.state}</span>{' '}
      <span>{blockers}</span>
    </>
  );
}

function EligibilityBadge({ row }: { row: ArrivalRow }) {
  return row.eligibility.eligible ? (
    <StatusBadge status="ready">Arrival Ready</StatusBadge>
  ) : (
    <StatusBadge status="warning">Blocked</StatusBadge>
  );
}

FrontDeskWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default FrontDeskWorkspace;
