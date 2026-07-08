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
  front_desk: {
    stay_id: string;
    status: string;
    current_room_id: string | null;
    current_room_assignment_id: string | null;
    current_room_number: string | null;
    checked_in_at: string | null;
  } | null;
  eligibility: {
    eligible: boolean;
    state: string;
    blockers: string[];
  };
  actions: {
    can_assign_room: boolean;
    can_prepare_check_in: boolean;
    can_view_in_house: boolean;
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

type CheckoutReadiness = {
  property_id: string;
  front_desk_stay_id: string;
  reservation_id: string;
  guest_id: string;
  current_room_id: string | null;
  current_room_assignment_id: string | null;
  readiness_status: string;
  operational_blockers: string[];
  evidence: {
    stay: { id: string; status: string | null; checked_in_at: string | null; checked_in_by: string | null };
    reservation: { id: string; number: string | null; arrival_date: string | null; departure_date: string | null; room_type: string | null; status: string | null };
    guest: { id: string; name: string | null; vip_level: number | null };
    current_room: { id: string | null; number: string | null; room_type: string | null; readiness_state: string };
    current_assignment: { id: string; assignment_kind: string | null; room_id: string; source_hash: string } | null;
    assignment_history: Array<{
      id: string;
      assignment_kind: string | null;
      room_id: string;
      room_number: string | null;
      assignment_reason: string | null;
      occurred_at: string | null;
      created_by: string;
      source_hash: string;
    }>;
    housekeeping: { source: string; readiness_state: string; blocking: boolean; blocking_reason: string | null };
    engineering: { source: string; availability_status: string; blocking: boolean; blocking_reason: string | null; blocking_source_type: string | null };
  };
  financial_marker: string;
  evaluated_at: string;
};

type InHouseRow = {
  stay_id: string;
  reservation: { id: string; number: string | null; arrival_date: string | null; departure_date: string | null; room_type: string | null };
  guest: { id: string; name: string | null; vip_level: number | null };
  status: string;
  current_room: { id: string | null; number: string | null; room_type: string | null };
  current_room_assignment_id: string | null;
  checked_in_at: string | null;
  checked_in_by: string | null;
  assignment_history: Array<{
    id: string;
    assignment_kind: string | null;
    room_id: string;
    room_number: string | null;
    assignment_reason: string | null;
    occurred_at: string | null;
    created_by: string;
    source_hash: string;
  }>;
  target_room_candidates: Array<{
    id: string;
    number: string;
    room_type: string | null;
    housekeeping: { readiness_state: string; cleanliness_status: string | null };
    engineering: { state: string; blocking_reason: string | null };
    eligible: boolean;
    blockers: string[];
  }>;
  actions: { can_move_room: boolean; can_view_checkout_readiness: boolean };
  checkout_readiness: CheckoutReadiness | null;
};

type InHouseWorkspace = {
  property: { id: string; name: string; company_id: string };
  snapshots: { inHouse: number; roomMoveReady: number; roomMoveBlocked: number };
  views: { inHouseStays: InHouseRow[] };
  financeMarker: string;
};

type Props = {
  activeTab?: string;
  arrivalWorkspace?: ArrivalWorkspace;
  inHouseWorkspace?: InHouseWorkspace;
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

const emptyInHouseWorkspace: InHouseWorkspace = {
  property: { id: '', name: 'Active Property', company_id: '' },
  snapshots: { inHouse: 0, roomMoveReady: 0, roomMoveBlocked: 0 },
  views: { inHouseStays: [] },
  financeMarker: 'Financial settlement: Not evaluated in Front Desk Package A.',
};

const FrontDeskWorkspace = ({ activeTab = 'arrivals', arrivalWorkspace = emptyWorkspace, inHouseWorkspace = emptyInHouseWorkspace }: Props) => {
  const tabs = [
    { href: '/frontdesk/arrivals', label: 'Arrivals', badge: arrivalWorkspace.snapshots.totalArrivals },
    { href: '/frontdesk/departures', label: 'Departures' },
    { href: '/frontdesk/in-house', label: 'In House' },
    { href: '/frontdesk/room-readiness', label: 'Room Readiness' },
    { href: '/frontdesk/reservation-board', label: 'Reservation Board' },
  ];

  return (
    <div className="workspace">
      <WorkspaceHeader title={activeTab === 'in_house' ? 'In-House Stays' : 'Arrival Queue'}>
        <Button variant="secondary">
          <Icon name="refresh" /> Refresh
        </Button>
      </WorkspaceHeader>

      <ModuleTabs tabs={tabs} />

      <SplitLayout>
        <QuickFilterPanel>
          {activeTab === 'in_house' ? (
            <div className="filter-group">
              <label className="filter-label">Room Move Control</label>
              <div className="filter-hint">Target room eligibility is server-projected from Housekeeping and Engineering.</div>
            </div>
          ) : (
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
          )}

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
            <div className="filter-hint">{(activeTab === 'in_house' ? inHouseWorkspace.financeMarker : arrivalWorkspace.financeMarker).replace('Financial settlement: ', '')}</div>
          </div>
        </QuickFilterPanel>

        <MainContent>
          {activeTab === 'in_house' ? (
            <>
              <OperationalSnapshot>
                <SnapshotCard value={inHouseWorkspace.snapshots.inHouse} label="In House" statusColor="ready-green" />
                <SnapshotCard value={inHouseWorkspace.snapshots.roomMoveReady} label="Move Ready" statusColor="ready-green" />
                <SnapshotCard value={inHouseWorkspace.snapshots.roomMoveBlocked} label="Move Blocked" statusColor="warning-amber" />
              </OperationalSnapshot>
              <InHouseQueue rows={inHouseWorkspace.views.inHouseStays} />
            </>
          ) : (
            <>
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
            </>
          )}
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
            actions={<ArrivalActions row={row} />}
          />
        ))
      )}
    </QueueList>
  );
}

function InHouseQueue({ rows }: { rows: InHouseRow[] }) {
  return (
    <QueueList title="In-House Guests" count={rows.length}>
      {rows.length === 0 ? (
        <QueueItem title="No in-house stays" meta="No IN_HOUSE Front Desk stay evidence matched the active property." />
      ) : (
        rows.map((row) => (
          <React.Fragment key={row.stay_id}>
            <QueueItem
              title={
                <>
                  {row.guest.name ?? 'Guest linkage missing'}
                  <StatusBadge status="ready" style={{ fontSize: '11px', padding: '2px 6px', marginLeft: '6px' }}>
                    In House
                  </StatusBadge>
                </>
              }
              meta={<InHouseMeta row={row} />}
              actions={<RoomMoveActions row={row} />}
            />
            {row.actions.can_view_checkout_readiness && row.checkout_readiness ? (
              <CheckoutReadinessPanel readiness={row.checkout_readiness} />
            ) : null}
          </React.Fragment>
        ))
      )}
    </QueueList>
  );
}

function InHouseMeta({ row }: { row: InHouseRow }) {
  const history = row.assignment_history
    .map((assignment) => `${assignment.assignment_kind} ${assignment.room_number ?? assignment.room_id}`)
    .join(' | ');
  const blockers = row.target_room_candidates
    .filter((candidate) => !candidate.eligible)
    .map((candidate) => `Room ${candidate.number}: ${candidate.blockers.join(' ')}`)
    .join(' | ');

  return (
    <>
      <span style={{ color: 'var(--text-primary)', fontWeight: 600 }}>{row.reservation.number ?? row.reservation.id}</span>{' '}
      <span>Room {row.current_room.number ?? row.current_room.id ?? 'Not configured'}</span>{' '}
      <span>Checked in {row.checked_in_at ?? 'Not configured'}</span>{' '}
      <span>Assignments {history || 'No assignment history'}</span>{' '}
      <span>{blockers || 'No room move blocker projected'}</span>
    </>
  );
}

function RoomMoveActions({ row }: { row: InHouseRow }) {
  const firstEligible = row.target_room_candidates.find((candidate) => candidate.eligible);

  return (
    <div style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
      <StatusBadge status="ready">{row.status}</StatusBadge>
      {row.actions.can_move_room && firstEligible ? (
        <form method="post" action={`/frontdesk/stays/${row.stay_id}/room-move-confirmation`}>
          <input type="hidden" name="target_room_id" value={firstEligible.id} />
          <input type="hidden" name="move_reason" value={`Operational room move to ${firstEligible.number}`} />
          <input type="hidden" name="idempotency_context" value={`room-move-${row.stay_id}-${firstEligible.id}`} />
          <Button size="sm">
            <Icon name="frontdesk" /> Room Move
          </Button>
        </form>
      ) : null}
    </div>
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
      <span>{row.front_desk?.current_room_number ? `Front Desk Room ${row.front_desk.current_room_number}` : room}</span>{' '}
      <span>Housekeeping {row.housekeeping.readiness_state ?? row.housekeeping.state}</span>{' '}
      <span>Engineering {row.engineering.state}</span>{' '}
      <span>{row.front_desk?.status ?? 'ARRIVAL_READY'}</span>{' '}
      <span>{blockers}</span>
    </>
  );
}

function ArrivalActions({ row }: { row: ArrivalRow }) {
  const canAssign = row.actions.can_assign_room && row.assigned_room !== null;
  const canPrepareCheckIn = row.actions.can_prepare_check_in && row.front_desk?.stay_id;

  return (
    <div style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
      {row.front_desk?.status === 'IN_HOUSE' ? (
        <StatusBadge status="ready">In House</StatusBadge>
      ) : row.eligibility.eligible ? (
        <StatusBadge status="ready">Arrival Ready</StatusBadge>
      ) : (
        <StatusBadge status="warning">Blocked</StatusBadge>
      )}

      {canAssign ? (
        <form method="post" action="/frontdesk/room-assignments">
          <input type="hidden" name="reservation_id" value={row.reservation_id} />
          <input type="hidden" name="room_id" value={row.assigned_room?.id ?? ''} />
          <input type="hidden" name="idempotency_key" value={`initial-${row.reservation_id}-${row.assigned_room?.id ?? ''}`} />
          <Button size="sm">
            <Icon name="frontdesk" /> Assign Room
          </Button>
        </form>
      ) : null}

      {canPrepareCheckIn ? (
        <form method="post" action={`/frontdesk/stays/${row.front_desk?.stay_id}/check-in-confirmation`}>
          <input type="hidden" name="idempotency_context" value={`check-in-${row.front_desk?.stay_id}`} />
          <Button size="sm">
            <Icon name="walk-in" /> Check In
          </Button>
        </form>
      ) : null}
    </div>
  );
}

function CheckoutReadinessPanel({ readiness }: { readiness: CheckoutReadiness }) {
  const statusColor = readiness.readiness_status === 'CHECKOUT_OPERATIONALLY_READY' ? 'ready-green'
    : readiness.readiness_status === 'CHECKOUT_OPERATIONALLY_BLOCKED' ? 'warning-amber'
    : 'neutral';

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '12px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' }}>
        <span style={{ fontWeight: 600, color: 'var(--text-primary)' }}>Check-out Readiness</span>
        <StatusBadge status="ready" style={{
          fontSize: '11px', padding: '2px 8px',
          backgroundColor: statusColor === 'ready-green' ? 'var(--status-ready-bg)' : statusColor === 'warning-amber' ? 'var(--status-pending-bg)' : 'var(--surface-disabled)',
          color: statusColor === 'ready-green' ? 'var(--status-ready-fg)' : statusColor === 'warning-amber' ? 'var(--status-pending-fg)' : 'var(--text-disabled)',
        }}>
          {readiness.readiness_status}
        </StatusBadge>
      </div>

      {readiness.operational_blockers.length > 0 ? (
        <div style={{ marginBottom: '6px' }}>
          <span style={{ fontWeight: 600, color: 'var(--text-secondary)' }}>Blockers: </span>
          <span style={{ color: 'var(--text-warning)' }}>{readiness.operational_blockers.join(' ')}</span>
        </div>
      ) : (
        <div style={{ marginBottom: '6px', color: 'var(--text-success)' }}>
          No operational blocker projected.
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '4px 12px', fontSize: '12px', color: 'var(--text-secondary)' }}>
        <span>Room: {readiness.evidence.current_room.number ?? readiness.current_room_id ?? 'N/A'} ({readiness.evidence.current_room.readiness_state})</span>
        <span>Assignment: {readiness.evidence.current_assignment?.assignment_kind ?? 'N/A'}</span>
        <span>Housekeeping: {readiness.evidence.housekeeping.readiness_state} {readiness.evidence.housekeeping.blocking ? '(blocking)' : ''}</span>
        <span>Engineering: {readiness.evidence.engineering.availability_status} {readiness.evidence.engineering.blocking ? `(${readiness.evidence.engineering.blocking_reason})` : ''}</span>
      </div>

      <div style={{ marginTop: '6px', fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>
        {readiness.financial_marker}
      </div>
    </div>
  );
}

FrontDeskWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default FrontDeskWorkspace;
