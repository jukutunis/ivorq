import React from 'react';
import '../../../../css/ivorq-prototype.css';

import { frontDeskData } from '../../../data/ivorq/frontDesk';
import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import AttentionCard from '../../../Components/Ivorq/patterns/AttentionCard';
import QueueList from '../../../Components/Ivorq/patterns/QueueList';
import QueueItem from '../../../Components/Ivorq/patterns/QueueItem';
import Button from '../../../Components/Ivorq/primitives/Button';
import StatusBadge from '../../../Components/Ivorq/primitives/StatusBadge';
import Icon from '../../../Components/Ivorq/primitives/Icon';

const FrontDeskWorkspace = ({ activeTab = 'arrivals' }: { activeTab?: string }) => {
  const tabs = [
    { href: '/frontdesk/arrivals', label: 'Arrivals', badge: 24 },
    { href: '/frontdesk/departures', label: 'Departures', badge: 18 },
    { href: '/frontdesk/in-house', label: 'In House', badge: 142 },
    { href: '/frontdesk/room-readiness', label: 'Room Readiness' },
    { href: '/frontdesk/reservation-board', label: 'Reservation Board' },
  ];

  return (
    <>
      <div className="workspace">
        <WorkspaceHeader title="Guest Arrival Operations">
          <Button variant="secondary">
            <Icon name="refresh" /> Refresh
          </Button>
          <Button variant="secondary">
            <Icon name="print" /> Print
          </Button>
          <Button variant="secondary">
            <Icon name="export-pdf" /> Export PDF
          </Button>
          <Button variant="secondary">
            <Icon name="export-xlsx" /> Export XLSX
          </Button>
          <Button variant="secondary">
            <Icon name="walk-in" /> Walk-In
          </Button>
          <Button variant="primary">
            <Icon name="new-reservation" /> New Reservation
          </Button>
        </WorkspaceHeader>

        <ModuleTabs tabs={tabs} />

        <SplitLayout>
          <QuickFilterPanel>
            <div className="filter-group"><label className="filter-label">Search</label><input type="text" className="filter-input" placeholder="Guest, Res #, Room..." /></div>
            <div className="filter-group"><label className="filter-label">Arrival Date</label><input type="date" className="filter-input" defaultValue="2026-06-14" /></div>
            <div className="filter-group">
              <label className="filter-label">Status</label>
              <select className="filter-input">
                <option>All</option>
                <option>Due In</option>
                <option>Early Arrival</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Payment</label>
              <select className="filter-input">
                <option>All</option>
                <option>Guaranteed</option>
                <option>Missing Guarantee</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Room Readiness</label>
              <select className="filter-input">
                <option>All</option>
                <option>Ready</option>
                <option>Not Ready</option>
                <option>No Room Assigned</option>
              </select>
            </div>
          </QuickFilterPanel>

          <MainContent>
            <OperationalSnapshot>
              <SnapshotCard value={frontDeskData.snapshots.totalArrivals} label="Total Arrivals" />
              <SnapshotCard value={frontDeskData.snapshots.vipDueIn} label="VIP Due In" statusColor="vip-gold" />
              <SnapshotCard value={frontDeskData.snapshots.noRoomAssigned} label="No Room Assigned" statusColor="critical-red" />
              <SnapshotCard value={frontDeskData.snapshots.roomsNotReady} label="Rooms Not Ready" statusColor="warning-amber" />
            </OperationalSnapshot>

            <AttentionArea title="Arrival Attention Needed" badgeText={`${frontDeskData.attentionItems.length} Issues`} badgeType="warning" areaType="warning">
              {frontDeskData.attentionItems.map((item) => (
                <AttentionCard
                  key={item.id}
                  title={
                    <>
                      {item.badge && <StatusBadge status="vip" style={{ marginRight: '4px' }}>{item.badge}</StatusBadge>}
                      {item.title}
                    </>
                  }
                  meta={item.meta}
                  actions={item.actions.map((act, i) => (
                    <Button key={i} variant="secondary" size="sm">{act}</Button>
                  ))}
                />
              ))}
            </AttentionArea>

            <QueueList title="Check-In Queue" count={frontDeskData.checkInQueue.length}>
              {frontDeskData.checkInQueue.map((item) => (
                <QueueItem
                  key={item.id}
                  title={
                    <>
                      {item.name}
                      {item.vip && <StatusBadge status="vip" style={{ fontSize: '11px', padding: '2px 6px', marginLeft: '6px' }}>VIP</StatusBadge>}
                    </>
                  }
                  meta={
                    <>
                      <span style={{ color: 'var(--text-primary)', fontWeight: 500 }}>{item.roomType}</span> •{' '}
                      <span
                        style={{
                          fontWeight: 600,
                          color: item.roomStatusReady
                            ? 'var(--ready-green)'
                            : item.roomStatusWarning
                            ? 'var(--warning-amber)'
                            : item.roomStatusCritical
                            ? 'var(--critical-red)'
                            : 'inherit',
                        }}
                      >
                        {item.roomStatus}
                      </span>{' '}
                      • <span style={{ fontSize: '11px', opacity: 0.7 }}>{item.reservationId}</span>
                    </>
                  }
                  actions={item.actions.map((act, i) => (
                    <Button
                      key={i}
                      variant={act.includes('Start') ? 'primary' : 'secondary'}
                      size="sm"
                    >
                      {act}
                    </Button>
                  ))}
                />
              ))}
            </QueueList>
          </MainContent>
        </SplitLayout>
      </div>
    </>
  );
};

FrontDeskWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default FrontDeskWorkspace;
