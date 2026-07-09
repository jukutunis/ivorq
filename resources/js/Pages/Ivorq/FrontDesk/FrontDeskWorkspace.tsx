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

type DeparturePreparationEvent = {
  id: string;
  event_type: string;
  event_type_label: string;
  note: string | null;
  occurred_at: string;
  created_by_name: string | null;
  source_hash: string;
};

type AllowedEventType = {
  value: string;
  label: string;
};

type DepartureOperationalHandoverEntry = {
  id: string;
  handover_status: string;
  handover_status_label: string;
  handover_note: string | null;
  occurred_at: string;
  created_by_name: string | null;
  source_hash: string;
};

type DepartureOperationalHandover = {
  latest: DepartureOperationalHandoverEntry;
  history: DepartureOperationalHandoverEntry[];
} | null;

type AllowedHandoverStatus = {
  value: string;
  label: string;
};

type ClosureReadinessEntry = {
  id: string;
  readiness_status: string;
  readiness_status_label: string;
  readiness_note: string | null;
  occurred_at: string;
  created_by_name: string | null;
  source_hash: string;
};

type ClosureReadiness = {
  latest: ClosureReadinessEntry;
  history: ClosureReadinessEntry[];
  b3_handover_dependency: {
    id: string;
    handover_status: string;
    handover_status_label: string;
    handover_note: string | null;
    occurred_at: string;
  } | null;
  b3_exists: boolean;
  b3_blocked: boolean;
  closure_readiness_warning: string | null;
} | null;

type AllowedClosureReadinessStatus = {
  value: string;
  label: string;
};

type CheckoutEligibilityEntry = {
  id: string;
  eligibility_status: string;
  eligibility_status_label: string;
  eligibility_note: string | null;
  occurred_at: string;
  created_by_name: string | null;
  source_hash: string;
};

type CheckoutEligibility = {
  latest: CheckoutEligibilityEntry;
  history: CheckoutEligibilityEntry[];
  b4_closure_readiness_dependency: {
    id: string;
    readiness_status: string;
    readiness_status_label: string;
    readiness_note: string | null;
    occurred_at: string;
  } | null;
  b4_exists: boolean;
  b4_blocked: boolean;
  checkout_eligibility_warning: string | null;
} | null;

type AllowedCheckoutEligibilityStatus = {
  value: string;
  label: string;
};

type CheckoutAuthorizationEntry = {
  id: string;
  authorization_status: string;
  authorization_status_label: string;
  authorization_note: string | null;
  occurred_at: string;
  created_by_name: string | null;
  source_hash: string;
};

type CheckoutAuthorization = {
  latest: CheckoutAuthorizationEntry;
  history: CheckoutAuthorizationEntry[];
  b5_eligibility_dependency: { id: string; eligibility_status: string; eligibility_status_label: string; eligibility_note: string | null; occurred_at: string } | null;
  b5_exists: boolean;
  b5_blocked: boolean;
  authorization_warning: string | null;
} | null;

type AllowedCheckoutAuthorizationStatus = {
  value: string;
  label: string;
};

type CheckoutFinalReviewEntry = {
  id: string;
  final_review_status: string;
  final_review_status_label: string;
  final_review_note: string | null;
  occurred_at: string;
  created_by_name: string | null;
  source_hash: string;
};

type CheckoutFinalReviewType = {
  latest: CheckoutFinalReviewEntry;
  history: CheckoutFinalReviewEntry[];
  b6_checkout_authorization_dependency: { id: string; authorization_status: string; authorization_status_label: string; authorization_note: string | null; occurred_at: string } | null;
  b6_exists: boolean;
  b6_blocked: boolean;
  final_review_warning: string | null;
} | null;

type AllowedCheckoutFinalReviewStatus = {
  value: string;
  label: string;
};

type DepartureRow = {
  stay_id: string;
  reservation_id: string;
  reservation_number: string | null;
  guest: { id: string; name: string | null; vip_level: number | null };
  room: { id: string | null; number: string | null; room_type: string | null };
  expected_departure_date: string | null;
  due_out_classification: string;
  front_desk_stay_status: string | null;
  current_room_assignment_id: string | null;
  checked_in_at: string | null;
  housekeeping_readiness_status: string;
  engineering_availability_status: string;
  operational_checkout_readiness: string;
  departure_readiness: string;
  blocking_reasons: string[];
  departure_preparation_events: DeparturePreparationEvent[];
  can_create_departure_preparation_event: boolean;
  allowed_event_types: AllowedEventType[];
  departure_operational_handover: DepartureOperationalHandover;
  can_create_operational_handover: boolean;
  allowed_handover_statuses: AllowedHandoverStatus[];
  departure_closure_readiness: ClosureReadiness;
  can_create_closure_readiness: boolean;
  allowed_closure_readiness_statuses: AllowedClosureReadinessStatus[];
  departure_checkout_eligibility: CheckoutEligibility;
  can_create_checkout_eligibility: boolean;
  allowed_checkout_eligibility_statuses: AllowedCheckoutEligibilityStatus[];
  departure_checkout_authorization: CheckoutAuthorization;
  can_create_checkout_authorization: boolean;
  allowed_checkout_authorization_statuses: AllowedCheckoutAuthorizationStatus[];
  departure_checkout_final_review: CheckoutFinalReviewType;
  can_create_checkout_final_review: boolean;
  allowed_checkout_final_review_statuses: AllowedCheckoutFinalReviewStatus[];
  financial_marker: string;
  evaluated_at: string;
};

type DepartureWorkspace = {
  property: { id: string; name: string; company_id: string };
  evaluated_at: string;
  snapshots: {
    dueOutToday: number;
    dueOutTomorrow: number;
    dueOutFuture: number;
    overdueDeparture: number;
    departureDateUnknown: number;
    departureOperationallyReady: number;
    departureOperationallyBlocked: number;
  };
  views: {
    dueOutToday: DepartureRow[];
    dueOutTomorrow: DepartureRow[];
    dueOutFuture: DepartureRow[];
    overdueDepartures: DepartureRow[];
  };
  financial_marker: string;
};

type Props = {
  activeTab?: string;
  arrivalWorkspace?: ArrivalWorkspace;
  inHouseWorkspace?: InHouseWorkspace;
  departureWorkspace?: DepartureWorkspace;
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

const emptyDepartureWorkspace: DepartureWorkspace = {
  property: { id: '', name: 'Active Property', company_id: '' },
  evaluated_at: '',
  snapshots: {
    dueOutToday: 0,
    dueOutTomorrow: 0,
    dueOutFuture: 0,
    overdueDeparture: 0,
    departureDateUnknown: 0,
    departureOperationallyReady: 0,
    departureOperationallyBlocked: 0,
  },
  views: {
    dueOutToday: [],
    dueOutTomorrow: [],
    dueOutFuture: [],
    overdueDepartures: [],
  },
  financial_marker: 'Financial settlement: Not evaluated in Front Desk Package B2.',
};

const FrontDeskWorkspace = ({ activeTab = 'arrivals', arrivalWorkspace = emptyWorkspace, inHouseWorkspace = emptyInHouseWorkspace, departureWorkspace = emptyDepartureWorkspace }: Props) => {
  const tabs = [
    { href: '/frontdesk/arrivals', label: 'Arrivals', badge: arrivalWorkspace.snapshots.totalArrivals },
    { href: '/frontdesk/departures', label: 'Departures' },
    { href: '/frontdesk/in-house', label: 'In House' },
    { href: '/frontdesk/room-readiness', label: 'Room Readiness' },
    { href: '/frontdesk/reservation-board', label: 'Reservation Board' },
  ];

  return (
    <div className="workspace">
      <WorkspaceHeader title={activeTab === 'in_house' ? 'In-House Stays' : activeTab === 'departures' ? 'Due-Out / Departure Preparation' : 'Arrival Queue'}>
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
            <div className="filter-hint">{(activeTab === 'in_house' ? inHouseWorkspace.financeMarker : activeTab === 'departures' ? departureWorkspace.financial_marker : arrivalWorkspace.financeMarker).replace('Financial settlement: ', '')}</div>
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
          ) : activeTab === 'departures' ? (
            <>
              <OperationalSnapshot>
                <SnapshotCard value={departureWorkspace.snapshots.dueOutToday} label="Due Out Today" statusColor="ready-green" />
                <SnapshotCard value={departureWorkspace.snapshots.overdueDeparture} label="Overdue" statusColor="warning-amber" />
                <SnapshotCard value={departureWorkspace.snapshots.departureOperationallyReady} label="Ready" statusColor="ready-green" />
                <SnapshotCard value={departureWorkspace.snapshots.departureOperationallyBlocked} label="Blocked" statusColor="warning-amber" />
                <SnapshotCard value={departureWorkspace.snapshots.departureDateUnknown} label="Date Unknown" />
              </OperationalSnapshot>
              <DepartureQueue title="Due Out Today" rows={departureWorkspace.views.dueOutToday} />
              <DepartureQueue title="Due Out Tomorrow" rows={departureWorkspace.views.dueOutTomorrow} />
              <DepartureQueue title="Overdue Departures" rows={departureWorkspace.views.overdueDepartures} />
              <DepartureQueue title="Future Due-Out" rows={departureWorkspace.views.dueOutFuture} />
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

function DepartureQueue({ title, rows }: { title: string; rows: DepartureRow[] }) {
  return (
    <QueueList title={title} count={rows.length}>
      {rows.length === 0 ? (
        <QueueItem title="No due-out stays" meta="No IN_HOUSE stays matched this departure window." />
      ) : (
        rows.map((row) => (
          <React.Fragment key={`${title}-${row.stay_id}`}>
            <QueueItem
              title={
                <>
                  {row.guest.name ?? 'Guest linkage missing'}
                  {row.guest.vip_level ? (
                    <StatusBadge status="vip" style={{ fontSize: '11px', padding: '2px 6px', marginLeft: '6px' }}>
                      VIP {row.guest.vip_level}
                    </StatusBadge>
                  ) : null}
                </>
              }
              meta={<DepartureMeta row={row} />}
              actions={<DepartureActions row={row} />}
            />
            {row.can_create_operational_handover ? (
              <DepartureOperationalHandoverForm stayId={row.stay_id} handoverStatuses={row.allowed_handover_statuses} />
            ) : null}
            {row.departure_operational_handover ? (
              <DepartureOperationalHandoverPanel handover={row.departure_operational_handover} />
            ) : null}
            {row.can_create_closure_readiness ? (
              <DepartureClosureReadinessForm stayId={row.stay_id} readinessStatuses={row.allowed_closure_readiness_statuses} />
            ) : null}
            {row.departure_closure_readiness ? (
              <DepartureClosureReadinessPanel readiness={row.departure_closure_readiness} />
            ) : null}
            {row.can_create_checkout_eligibility ? (
              <CheckoutEligibilityForm stayId={row.stay_id} eligibilityStatuses={row.allowed_checkout_eligibility_statuses} />
            ) : null}
            {row.departure_checkout_eligibility ? (
              <CheckoutEligibilityPanel eligibility={row.departure_checkout_eligibility} />
            ) : null}
            {row.can_create_checkout_authorization ? (
              <CheckoutAuthorizationForm stayId={row.stay_id} authorizationStatuses={row.allowed_checkout_authorization_statuses} />
            ) : null}
            {row.departure_checkout_authorization ? (
              <CheckoutAuthorizationPanel authorization={row.departure_checkout_authorization} />
            ) : null}
            {row.can_create_checkout_final_review ? (
              <CheckoutFinalReviewForm stayId={row.stay_id} reviewStatuses={row.allowed_checkout_final_review_statuses} />
            ) : null}
            {row.departure_checkout_final_review ? (
              <CheckoutFinalReviewPanel review={row.departure_checkout_final_review} />
            ) : null}
            {row.can_create_departure_preparation_event ? (
              <DepartureActionForm stayId={row.stay_id} eventTypes={row.allowed_event_types} />
            ) : null}
            {row.departure_preparation_events.length > 0 ? (
              <DepartureActionLogPanel events={row.departure_preparation_events} />
            ) : null}
            {row.blocking_reasons.length > 0 ? (
              <DepartureBlockersPanel blockers={row.blocking_reasons} readiness={row.departure_readiness} />
            ) : null}
          </React.Fragment>
        ))
      )}
    </QueueList>
  );
}

function DepartureMeta({ row }: { row: DepartureRow }) {
  const dueOutLabel = row.due_out_classification.replace(/_/g, ' ');

  return (
    <>
      <span style={{ color: 'var(--text-primary)', fontWeight: 600 }}>{row.reservation_number ?? row.reservation_id}</span>{' '}
      <span>Room {row.room.number ?? row.room.id ?? 'N/A'}</span>{' '}
      <span>{row.room.room_type ?? 'N/A'}</span>{' '}
      <span>Departure {row.expected_departure_date ?? 'Unknown'}</span>{' '}
      <span>{dueOutLabel}</span>{' '}
      <span>HK: {row.housekeeping_readiness_status}</span>{' '}
      <span>ENG: {row.engineering_availability_status}</span>{' '}
      <span>Checkout: {row.operational_checkout_readiness}</span>
    </>
  );
}

function DepartureActions({ row }: { row: DepartureRow }) {
  const readinessColor = row.departure_readiness === 'DEPARTURE_OPERATIONALLY_READY' ? 'ready-green'
    : row.departure_readiness === 'DEPARTURE_OPERATIONALLY_BLOCKED' ? 'warning-amber'
    : 'neutral';

  const dueOutColor = row.due_out_classification === 'DUE_OUT_TODAY' ? 'ready-green'
    : row.due_out_classification === 'OVERDUE_DEPARTURE' ? 'warning-amber'
    : row.due_out_classification === 'DUE_OUT_TOMORROW' ? 'ready-green'
    : 'neutral';

  return (
    <div style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
      <StatusBadge status="ready" style={{
        fontSize: '11px', padding: '2px 8px',
        backgroundColor: dueOutColor === 'ready-green' ? 'var(--status-ready-bg)' : dueOutColor === 'warning-amber' ? 'var(--status-pending-bg)' : 'var(--surface-disabled)',
        color: dueOutColor === 'ready-green' ? 'var(--status-ready-fg)' : dueOutColor === 'warning-amber' ? 'var(--status-pending-fg)' : 'var(--text-disabled)',
      }}>
        {row.due_out_classification.replace(/_/g, ' ')}
      </StatusBadge>
      <StatusBadge status="ready" style={{
        fontSize: '11px', padding: '2px 8px',
        backgroundColor: readinessColor === 'ready-green' ? 'var(--status-ready-bg)' : readinessColor === 'warning-amber' ? 'var(--status-pending-bg)' : 'var(--surface-disabled)',
        color: readinessColor === 'ready-green' ? 'var(--status-ready-fg)' : readinessColor === 'warning-amber' ? 'var(--status-pending-fg)' : 'var(--text-disabled)',
      }}>
        {row.departure_readiness.replace(/_/g, ' ')}
      </StatusBadge>
    </div>
  );
}

function DepartureActionForm({ stayId, eventTypes }: { stayId: string; eventTypes: AllowedEventType[] }) {
  const [showForm, setShowForm] = React.useState(false);
  const [selectedType, setSelectedType] = React.useState(eventTypes[0]?.value ?? '');

  if (eventTypes.length === 0) return null;

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      {!showForm ? (
        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
          {eventTypes.map((et) => (
            <Button key={et.value} size="sm" variant="secondary" onClick={() => { setShowForm(true); setSelectedType(et.value); }}>
              <Icon name="note" /> {et.label}
            </Button>
          ))}
        </div>
      ) : (
        <form method="post" action={`/frontdesk/stays/${stayId}/departure-preparation-events`} onSubmit={() => setShowForm(false)}>
          <input type="hidden" name="event_type" value={selectedType} />
          <input type="hidden" name="idempotency_key" value={`dpe-${stayId}-${selectedType}-${Date.now()}`} />
          <div style={{ marginBottom: '8px' }}>
            <label style={{ fontWeight: 600, color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>
              {eventTypes.find((et) => et.value === selectedType)?.label ?? selectedType}
            </label>
            <textarea name="note" rows={2} style={{
              width: '100%', padding: '6px 8px', fontSize: '13px',
              border: '1px solid var(--border-subtle)', borderRadius: '4px',
              background: 'var(--surface-input)', color: 'var(--text-primary)',
            }} placeholder="Optional note..." />
          </div>
          <div style={{ display: 'flex', gap: '6px' }}>
            <Button size="sm" type="submit"><Icon name="save" /> Record</Button>
            <Button size="sm" variant="secondary" onClick={(e: React.MouseEvent) => { e.preventDefault(); setShowForm(false); }}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}

function DepartureActionLogPanel({ events }: { events: DeparturePreparationEvent[] }) {
  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      <div style={{ fontWeight: 600, color: 'var(--text-primary)', marginBottom: '8px' }}>
        Departure Preparation Action Log ({events.length})
      </div>
      {events.map((event) => (
        <div key={event.id} style={{
          display: 'flex', alignItems: 'baseline', gap: '12px',
          padding: '4px 0', borderBottom: '1px solid var(--border-subtle)',
          fontSize: '12px',
        }}>
          <StatusBadge status="ready" style={{ fontSize: '10px', padding: '1px 6px', flexShrink: 0 }}>
            {event.event_type_label}
          </StatusBadge>
          <span style={{ color: 'var(--text-secondary)', flex: 1 }}>
            {event.note ?? '—'}
          </span>
          <span style={{ color: 'var(--text-dimmed)', fontSize: '11px', flexShrink: 0 }}>
            {event.created_by_name ?? 'System'}, {event.occurred_at ? new Date(event.occurred_at).toLocaleString() : ''}
          </span>
        </div>
      ))}
    </div>
  );
}

function DepartureOperationalHandoverForm({ stayId, handoverStatuses }: { stayId: string; handoverStatuses: AllowedHandoverStatus[] }) {
  const [showForm, setShowForm] = React.useState(false);
  const [selectedStatus, setSelectedStatus] = React.useState(handoverStatuses[0]?.value ?? '');

  if (handoverStatuses.length === 0) return null;

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      {!showForm ? (
        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontWeight: 600, color: 'var(--text-primary)', marginRight: '4px' }}>Operational Handover:</span>
          {handoverStatuses.map((hs) => (
            <Button key={hs.value} size="sm" variant="secondary" onClick={() => { setShowForm(true); setSelectedStatus(hs.value); }}>
              <Icon name="note" /> {hs.label}
            </Button>
          ))}
        </div>
      ) : (
        <form method="post" action={`/frontdesk/stays/${stayId}/departure-operational-handovers`} onSubmit={() => setShowForm(false)}>
          <input type="hidden" name="handover_status" value={selectedStatus} />
          <input type="hidden" name="idempotency_key" value={`doh-${stayId}-${selectedStatus}-${Date.now()}`} />
          <div style={{ marginBottom: '8px' }}>
            <label style={{ fontWeight: 600, color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>
              Handover Note (Optional)
            </label>
            <textarea name="handover_note" rows={2} style={{
              width: '100%', padding: '6px 8px', fontSize: '13px',
              border: '1px solid var(--border-subtle)', borderRadius: '4px',
              background: 'var(--surface-input)', color: 'var(--text-primary)',
            }} placeholder="Optional handover note..." />
          </div>
          <div style={{ display: 'flex', gap: '6px' }}>
            <Button size="sm" type="submit"><Icon name="save" /> Record Handover</Button>
            <Button size="sm" variant="secondary" onClick={(e: React.MouseEvent) => { e.preventDefault(); setShowForm(false); }}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}

function DepartureOperationalHandoverPanel({ handover }: { handover: NonNullable<DepartureOperationalHandover> }) {
  const statusColor = handover.latest.handover_status === 'OPERATIONAL_HANDOVER_READY' ? 'ready-green'
    : handover.latest.handover_status === 'OPERATIONAL_HANDOVER_BLOCKED' ? 'warning-amber'
    : handover.latest.handover_status === 'OPERATIONAL_HANDOVER_REVIEWED' ? 'ready-green'
    : 'neutral';

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      <div style={{ fontWeight: 600, color: 'var(--text-primary)', marginBottom: '8px' }}>
        Operational Handover History ({handover.history.length})
      </div>
      {handover.history.map((entry) => (
        <div key={entry.id} style={{
          display: 'flex', alignItems: 'baseline', gap: '12px',
          padding: '4px 0', borderBottom: '1px solid var(--border-subtle)',
          fontSize: '12px',
        }}>
          <StatusBadge status="ready" style={{
            fontSize: '10px', padding: '1px 8px', flexShrink: 0,
            backgroundColor: entry.handover_status === 'OPERATIONAL_HANDOVER_READY' || entry.handover_status === 'OPERATIONAL_HANDOVER_REVIEWED'
              ? 'var(--status-ready-bg)' : 'var(--status-pending-bg)',
            color: entry.handover_status === 'OPERATIONAL_HANDOVER_READY' || entry.handover_status === 'OPERATIONAL_HANDOVER_REVIEWED'
              ? 'var(--status-ready-fg)' : 'var(--status-pending-fg)',
          }}>
            {entry.handover_status_label}
          </StatusBadge>
          <span style={{ color: 'var(--text-secondary)', flex: 1 }}>
            {entry.handover_note ?? '—'}
          </span>
          <span style={{ color: 'var(--text-dimmed)', fontSize: '11px', flexShrink: 0 }}>
            {entry.created_by_name ?? 'System'}, {entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : ''}
          </span>
        </div>
      ))}
    </div>
  );
}

function DepartureBlockersPanel({ blockers, readiness }: { blockers: string[]; readiness: string }) {
  const statusColor = readiness === 'DEPARTURE_OPERATIONALLY_BLOCKED' ? 'warning-amber' : 'neutral';

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '12px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' }}>
        <span style={{ fontWeight: 600, color: 'var(--text-primary)' }}>Departure Readiness</span>
        <StatusBadge status="ready" style={{
          fontSize: '11px', padding: '2px 8px',
          backgroundColor: statusColor === 'warning-amber' ? 'var(--status-pending-bg)' : 'var(--surface-disabled)',
          color: statusColor === 'warning-amber' ? 'var(--status-pending-fg)' : 'var(--text-disabled)',
        }}>
          {readiness.replace(/_/g, ' ')}
        </StatusBadge>
      </div>
      <div style={{ marginBottom: '6px' }}>
        <span style={{ fontWeight: 600, color: 'var(--text-secondary)' }}>Blockers: </span>
        <span style={{ color: 'var(--text-warning)' }}>{blockers.join(' | ')}</span>
      </div>
      <div style={{ marginTop: '6px', fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>
        Financial settlement: Not evaluated in Front Desk Package B3.
      </div>
    </div>
  );
}

function DepartureClosureReadinessForm({ stayId, readinessStatuses }: { stayId: string; readinessStatuses: AllowedClosureReadinessStatus[] }) {
  const [showForm, setShowForm] = React.useState(false);
  const [selectedStatus, setSelectedStatus] = React.useState(readinessStatuses[0]?.value ?? '');

  if (readinessStatuses.length === 0) return null;

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      {!showForm ? (
        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontWeight: 600, color: 'var(--text-primary)', marginRight: '4px' }}>Closure Readiness:</span>
          {readinessStatuses.map((rs) => (
            <Button key={rs.value} size="sm" variant="secondary" onClick={() => { setShowForm(true); setSelectedStatus(rs.value); }}>
              <Icon name="note" /> {rs.label}
            </Button>
          ))}
        </div>
      ) : (
        <form method="post" action={`/frontdesk/stays/${stayId}/departure-closure-readiness`} onSubmit={() => setShowForm(false)}>
          <input type="hidden" name="readiness_status" value={selectedStatus} />
          <input type="hidden" name="idempotency_key" value={`dcr-${stayId}-${selectedStatus}-${Date.now()}`} />
          <div style={{ marginBottom: '8px' }}>
            <label style={{ fontWeight: 600, color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>
              Readiness Note (Optional)
            </label>
            <textarea name="readiness_note" rows={2} style={{
              width: '100%', padding: '6px 8px', fontSize: '13px',
              border: '1px solid var(--border-subtle)', borderRadius: '4px',
              background: 'var(--surface-input)', color: 'var(--text-primary)',
            }} placeholder="Optional readiness note..." />
          </div>
          <div style={{ display: 'flex', gap: '6px' }}>
            <Button size="sm" type="submit"><Icon name="save" /> Record Readiness</Button>
            <Button size="sm" variant="secondary" onClick={(e: React.MouseEvent) => { e.preventDefault(); setShowForm(false); }}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}

function DepartureClosureReadinessPanel({ readiness }: { readiness: NonNullable<ClosureReadiness> }) {
  const statusColor = readiness.latest.readiness_status === 'CLOSURE_READY' ? 'ready-green'
    : readiness.latest.readiness_status === 'CLOSURE_BLOCKED' ? 'warning-amber'
    : readiness.latest.readiness_status === 'CLOSURE_REVIEWED' ? 'ready-green'
    : 'neutral';

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      <div style={{ fontWeight: 600, color: 'var(--text-primary)', marginBottom: '8px' }}>
        Closure Readiness History ({readiness.history.length})
      </div>
      {readiness.closure_readiness_warning ? (
        <div style={{
          padding: '6px 10px', marginBottom: '8px',
          backgroundColor: 'var(--status-pending-bg)',
          color: 'var(--status-pending-fg)',
          borderRadius: '4px', fontSize: '12px', fontWeight: 500,
        }}>
          {readiness.closure_readiness_warning}
        </div>
      ) : null}
      {readiness.history.map((entry) => (
        <div key={entry.id} style={{
          display: 'flex', alignItems: 'baseline', gap: '12px',
          padding: '4px 0', borderBottom: '1px solid var(--border-subtle)',
          fontSize: '12px',
        }}>
          <StatusBadge status="ready" style={{
            fontSize: '10px', padding: '1px 8px', flexShrink: 0,
            backgroundColor: entry.readiness_status === 'CLOSURE_READY' || entry.readiness_status === 'CLOSURE_REVIEWED'
              ? 'var(--status-ready-bg)' : 'var(--status-pending-bg)',
            color: entry.readiness_status === 'CLOSURE_READY' || entry.readiness_status === 'CLOSURE_REVIEWED'
              ? 'var(--status-ready-fg)' : 'var(--status-pending-fg)',
          }}>
            {entry.readiness_status_label}
          </StatusBadge>
          <span style={{ color: 'var(--text-secondary)', flex: 1 }}>
            {entry.readiness_note ?? '—'}
          </span>
          <span style={{ color: 'var(--text-dimmed)', fontSize: '11px', flexShrink: 0 }}>
            {entry.created_by_name ?? 'System'}, {entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : ''}
          </span>
        </div>
      ))}
      <div style={{ marginTop: '6px', fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>
        Financial settlement: Not evaluated in Front Desk Package B4.
      </div>
    </div>
  );
}

function CheckoutEligibilityForm({ stayId, eligibilityStatuses }: { stayId: string; eligibilityStatuses: AllowedCheckoutEligibilityStatus[] }) {
  const [showForm, setShowForm] = React.useState(false);
  const [selectedStatus, setSelectedStatus] = React.useState(eligibilityStatuses[0]?.value ?? '');

  if (eligibilityStatuses.length === 0) return null;

  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      {!showForm ? (
        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontWeight: 600, color: 'var(--text-primary)', marginRight: '4px' }}>Checkout Eligibility:</span>
          {eligibilityStatuses.map((es) => (
            <Button key={es.value} size="sm" variant="secondary" onClick={() => { setShowForm(true); setSelectedStatus(es.value); }}>
              <Icon name="note" /> {es.label}
            </Button>
          ))}
        </div>
      ) : (
        <form method="post" action={`/frontdesk/stays/${stayId}/departure-checkout-eligibility`} onSubmit={() => setShowForm(false)}>
          <input type="hidden" name="eligibility_status" value={selectedStatus} />
          <input type="hidden" name="idempotency_key" value={`dce-${stayId}-${selectedStatus}-${Date.now()}`} />
          <div style={{ marginBottom: '8px' }}>
            <label style={{ fontWeight: 600, color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>
              Eligibility Note (Optional)
            </label>
            <textarea name="eligibility_note" rows={2} style={{
              width: '100%', padding: '6px 8px', fontSize: '13px',
              border: '1px solid var(--border-subtle)', borderRadius: '4px',
              background: 'var(--surface-input)', color: 'var(--text-primary)',
            }} placeholder="Optional eligibility note..." />
          </div>
          <div style={{ display: 'flex', gap: '6px' }}>
            <Button size="sm" type="submit"><Icon name="save" /> Record Eligibility</Button>
            <Button size="sm" variant="secondary" onClick={(e: React.MouseEvent) => { e.preventDefault(); setShowForm(false); }}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}

function CheckoutEligibilityPanel({ eligibility }: { eligibility: NonNullable<CheckoutEligibility> }) {
  return (
    <div style={{
      borderTop: '1px solid var(--border-subtle)',
      padding: '10px 16px',
      background: 'var(--surface-raised)',
      fontSize: '13px',
    }}>
      <div style={{ fontWeight: 600, color: 'var(--text-primary)', marginBottom: '8px' }}>
        Checkout Eligibility History ({eligibility.history.length})
      </div>
      {eligibility.checkout_eligibility_warning ? (
        <div style={{
          padding: '6px 10px', marginBottom: '8px',
          backgroundColor: 'var(--status-pending-bg)',
          color: 'var(--status-pending-fg)',
          borderRadius: '4px', fontSize: '12px', fontWeight: 500,
        }}>
          {eligibility.checkout_eligibility_warning}
        </div>
      ) : null}
      {eligibility.history.map((entry) => (
        <div key={entry.id} style={{
          display: 'flex', alignItems: 'baseline', gap: '12px',
          padding: '4px 0', borderBottom: '1px solid var(--border-subtle)',
          fontSize: '12px',
        }}>
          <StatusBadge status="ready" style={{
            fontSize: '10px', padding: '1px 8px', flexShrink: 0,
            backgroundColor: entry.eligibility_status === 'CHECKOUT_ELIGIBLE' || entry.eligibility_status === 'CHECKOUT_REVIEWED'
              ? 'var(--status-ready-bg)' : 'var(--status-pending-bg)',
            color: entry.eligibility_status === 'CHECKOUT_ELIGIBLE' || entry.eligibility_status === 'CHECKOUT_REVIEWED'
              ? 'var(--status-ready-fg)' : 'var(--status-pending-fg)',
          }}>
            {entry.eligibility_status_label}
          </StatusBadge>
          <span style={{ color: 'var(--text-secondary)', flex: 1 }}>
            {entry.eligibility_note ?? '—'}
          </span>
          <span style={{ color: 'var(--text-dimmed)', fontSize: '11px', flexShrink: 0 }}>
            {entry.created_by_name ?? 'System'}, {entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : ''}
          </span>
        </div>
      ))}
      <div style={{ marginTop: '6px', fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>
        Financial settlement: Not evaluated in Front Desk Package B5.
      </div>
    </div>
  );
}

function CheckoutAuthorizationForm({ stayId, authorizationStatuses }: { stayId: string; authorizationStatuses: AllowedCheckoutAuthorizationStatus[] }) {
  const [showForm, setShowForm] = React.useState(false);
  const [selectedStatus, setSelectedStatus] = React.useState(authorizationStatuses[0]?.value ?? '');
  if (authorizationStatuses.length === 0) return null;
  return (
    <div style={{ borderTop: '1px solid var(--border-subtle)', padding: '10px 16px', background: 'var(--surface-raised)', fontSize: '13px' }}>
      {!showForm ? (
        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontWeight: 600, color: 'var(--text-primary)', marginRight: '4px' }}>Checkout Authorization:</span>
          {authorizationStatuses.map((as: AllowedCheckoutAuthorizationStatus) => (
            <Button key={as.value} size="sm" variant="secondary" onClick={() => { setShowForm(true); setSelectedStatus(as.value); }}>
              <Icon name="note" /> {as.label}
            </Button>
          ))}
        </div>
      ) : (
        <form method="post" action={`/frontdesk/stays/${stayId}/departure-checkout-authorization`} onSubmit={() => setShowForm(false)}>
          <input type="hidden" name="authorization_status" value={selectedStatus} />
          <input type="hidden" name="idempotency_key" value={`dca-${stayId}-${selectedStatus}-${Date.now()}`} />
          <div style={{ marginBottom: '8px' }}><label style={{ fontWeight: 600, color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>Authorization Note (Optional)</label>
            <textarea name="authorization_note" rows={2} style={{ width: '100%', padding: '6px 8px', fontSize: '13px', border: '1px solid var(--border-subtle)', borderRadius: '4px', background: 'var(--surface-input)', color: 'var(--text-primary)' }} placeholder="Optional authorization note..." />
          </div>
          <div style={{ display: 'flex', gap: '6px' }}><Button size="sm" type="submit"><Icon name="save" /> Record Authorization</Button><Button size="sm" variant="secondary" onClick={(e: React.MouseEvent) => { e.preventDefault(); setShowForm(false); }}>Cancel</Button></div>
        </form>
      )}
    </div>
  );
}

function CheckoutAuthorizationPanel({ authorization }: { authorization: NonNullable<CheckoutAuthorization> }) {
  return (
    <div style={{ borderTop: '1px solid var(--border-subtle)', padding: '10px 16px', background: 'var(--surface-raised)', fontSize: '13px' }}>
      <div style={{ fontWeight: 600, color: 'var(--text-primary)', marginBottom: '8px' }}>Checkout Authorization History ({authorization.history.length})</div>
      {authorization.authorization_warning ? (<div style={{ padding: '6px 10px', marginBottom: '8px', backgroundColor: 'var(--status-pending-bg)', color: 'var(--status-pending-fg)', borderRadius: '4px', fontSize: '12px', fontWeight: 500 }}>{authorization.authorization_warning}</div>) : null}
      {authorization.history.map((entry: CheckoutAuthorizationEntry) => (
        <div key={entry.id} style={{ display: 'flex', alignItems: 'baseline', gap: '12px', padding: '4px 0', borderBottom: '1px solid var(--border-subtle)', fontSize: '12px' }}>
          <StatusBadge status="ready" style={{ fontSize: '10px', padding: '1px 8px', flexShrink: 0, backgroundColor: entry.authorization_status === 'CHECKOUT_AUTHORIZATION_READY' || entry.authorization_status === 'CHECKOUT_AUTHORIZATION_REVIEWED' ? 'var(--status-ready-bg)' : 'var(--status-pending-bg)', color: entry.authorization_status === 'CHECKOUT_AUTHORIZATION_READY' || entry.authorization_status === 'CHECKOUT_AUTHORIZATION_REVIEWED' ? 'var(--status-ready-fg)' : 'var(--status-pending-fg)' }}>{entry.authorization_status_label}</StatusBadge>
          <span style={{ color: 'var(--text-secondary)', flex: 1 }}>{entry.authorization_note ?? '—'}</span>
          <span style={{ color: 'var(--text-dimmed)', fontSize: '11px', flexShrink: 0 }}>{entry.created_by_name ?? 'System'}, {entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : ''}</span>
        </div>
      ))}
      <div style={{ marginTop: '6px', fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>Checkout execution: Not performed in Front Desk Package B6.</div>
    </div>
  );
}

function CheckoutFinalReviewForm({ stayId, reviewStatuses }: { stayId: string; reviewStatuses: AllowedCheckoutFinalReviewStatus[] }) {
  const [showForm, setShowForm] = React.useState(false);
  const [selectedStatus, setSelectedStatus] = React.useState(reviewStatuses[0]?.value ?? '');
  if (reviewStatuses.length === 0) return null;
  return (
    <div style={{ borderTop: '1px solid var(--border-subtle)', padding: '10px 16px', background: 'var(--surface-raised)', fontSize: '13px' }}>
      {!showForm ? (
        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontWeight: 600, color: 'var(--text-primary)', marginRight: '4px' }}>Checkout Final Review:</span>
          {reviewStatuses.map((rs: AllowedCheckoutFinalReviewStatus) => (
            <Button key={rs.value} size="sm" variant="secondary" onClick={() => { setShowForm(true); setSelectedStatus(rs.value); }}>
              <Icon name="note" /> {rs.label}
            </Button>
          ))}
        </div>
      ) : (
        <form method="post" action={`/frontdesk/stays/${stayId}/departure-checkout-final-review`} onSubmit={() => setShowForm(false)}>
          <input type="hidden" name="final_review_status" value={selectedStatus} />
          <input type="hidden" name="idempotency_key" value={`dcfr-${stayId}-${selectedStatus}-${Date.now()}`} />
          <div style={{ marginBottom: '8px' }}><label style={{ fontWeight: 600, color: 'var(--text-primary)', display: 'block', marginBottom: '4px' }}>Final Review Note (Optional)</label>
            <textarea name="final_review_note" rows={2} style={{ width: '100%', padding: '6px 8px', fontSize: '13px', border: '1px solid var(--border-subtle)', borderRadius: '4px', background: 'var(--surface-input)', color: 'var(--text-primary)' }} placeholder="Optional final review note..." />
          </div>
          <div style={{ display: 'flex', gap: '6px' }}><Button size="sm" type="submit"><Icon name="save" /> Record Final Review</Button><Button size="sm" variant="secondary" onClick={(e: React.MouseEvent) => { e.preventDefault(); setShowForm(false); }}>Cancel</Button></div>
        </form>
      )}
    </div>
  );
}

function CheckoutFinalReviewPanel({ review }: { review: NonNullable<CheckoutFinalReviewType> }) {
  return (
    <div style={{ borderTop: '1px solid var(--border-subtle)', padding: '10px 16px', background: 'var(--surface-raised)', fontSize: '13px' }}>
      <div style={{ fontWeight: 600, color: 'var(--text-primary)', marginBottom: '8px' }}>Checkout Final Review History ({review.history.length})</div>
      {review.final_review_warning ? (<div style={{ padding: '6px 10px', marginBottom: '8px', backgroundColor: 'var(--status-pending-bg)', color: 'var(--status-pending-fg)', borderRadius: '4px', fontSize: '12px', fontWeight: 500 }}>{review.final_review_warning}</div>) : null}
      {review.b6_checkout_authorization_dependency ? (
        <div style={{ padding: '4px 8px', marginBottom: '8px', backgroundColor: 'var(--surface-input)', borderRadius: '4px', fontSize: '11px', color: 'var(--text-dimmed)' }}>
          B6 Authorization: <StatusBadge status="ready" style={{ fontSize: '10px', padding: '1px 6px', marginLeft: '6px', backgroundColor: review.b6_checkout_authorization_dependency.authorization_status === 'CHECKOUT_AUTHORIZATION_READY' ? 'var(--status-ready-bg)' : 'var(--status-pending-bg)', color: review.b6_checkout_authorization_dependency.authorization_status === 'CHECKOUT_AUTHORIZATION_READY' ? 'var(--status-ready-fg)' : 'var(--status-pending-fg)' }}>{review.b6_checkout_authorization_dependency.authorization_status_label}</StatusBadge>
        </div>
      ) : null}
      {review.history.map((entry: CheckoutFinalReviewEntry) => (
        <div key={entry.id} style={{ display: 'flex', alignItems: 'baseline', gap: '12px', padding: '4px 0', borderBottom: '1px solid var(--border-subtle)', fontSize: '12px' }}>
          <StatusBadge status="ready" style={{ fontSize: '10px', padding: '1px 8px', flexShrink: 0, backgroundColor: entry.final_review_status === 'CHECKOUT_FINAL_REVIEW_READY' || entry.final_review_status === 'CHECKOUT_FINAL_REVIEW_REVIEWED' ? 'var(--status-ready-bg)' : 'var(--status-pending-bg)', color: entry.final_review_status === 'CHECKOUT_FINAL_REVIEW_READY' || entry.final_review_status === 'CHECKOUT_FINAL_REVIEW_REVIEWED' ? 'var(--status-ready-fg)' : 'var(--status-pending-fg)' }}>{entry.final_review_status_label}</StatusBadge>
          <span style={{ color: 'var(--text-secondary)', flex: 1 }}>{entry.final_review_note ?? '—'}</span>
          <span style={{ color: 'var(--text-dimmed)', fontSize: '11px', flexShrink: 0 }}>{entry.created_by_name ?? 'System'}, {entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : ''}</span>
        </div>
      ))}
      <div style={{ marginTop: '6px', fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>Checkout execution: Not performed in Front Desk Package B7.</div>
      <div style={{ fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>Stay closure: Not performed in Front Desk Package B7.</div>
      <div style={{ fontSize: '11px', color: 'var(--text-dimmed)', fontStyle: 'italic' }}>Financial settlement: Not evaluated in Front Desk Package B7.</div>
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
