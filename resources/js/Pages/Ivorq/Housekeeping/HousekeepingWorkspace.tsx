import React, { useState } from 'react';
import '../../../../css/ivorq-prototype.css';

import { housekeepingData } from '../../../data/ivorq/housekeeping';
import IvorqLayout from '../../../Layouts/IvorqLayout';

import AppTopbar from '../../../Components/Ivorq/shell/AppTopbar';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';

import BoardHeader from '../../../Components/Ivorq/housekeeping/BoardHeader';
import WorkBoard from '../../../Components/Ivorq/housekeeping/WorkBoard';
import BoardColumn from '../../../Components/Ivorq/housekeeping/BoardColumn';
import WorkCard from '../../../Components/Ivorq/housekeeping/WorkCard';

import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';
import StatusBadge from '../../../Components/Ivorq/primitives/StatusBadge';

const HousekeepingWorkspace = () => {
  const [activeTab, setActiveTab] = useState('room_board');

  const tabs = [
    { id: 'room_board', label: 'Room Board' },
    { id: 'assignments', label: 'Assignments' },
    { id: 'inspections', label: 'Inspections', badge: 15 },
    { id: 'lost_found', label: 'Lost & Found' },
  ];

  return (
    <>
      <div className="workspace">
        <WorkspaceHeader title="Housekeeping">
          <Button variant="secondary">
            <Icon name="print" /> Print
          </Button>
          <Button variant="secondary">
            <Icon name="export-xlsx" /> Export XLSX
          </Button>
        </WorkspaceHeader>

        <ModuleTabs tabs={tabs} activeTabId={activeTab} onTabChange={setActiveTab} />

        <SplitLayout>
          <QuickFilterPanel>
            <div className="filter-group">
              <label className="filter-label">Floor / Zone</label>
              <select className="filter-input">
                <option>All Floors</option>
                <option>Floor 1</option>
                <option>Floor 2</option>
                <option>Floor 3</option>
                <option>Floor 4</option>
                <option>Floor 5</option>
                <option>Floor 6</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Room Status</label>
              <select className="filter-input">
                <option>All</option>
                <option>Dirty</option>
                <option>Clean</option>
                <option>Inspected</option>
                <option>OOO</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Attendant</label>
              <select className="filter-input">
                <option>All Attendants</option>
                <option>Ni Luh Sari</option>
                <option>Wayan Darma</option>
                <option>Ketut Ari</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Due Time</label>
              <select className="filter-input">
                <option>All</option>
                <option>Overdue</option>
                <option>Next 1 Hour</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Priority</label>
              <select className="filter-input">
                <option>All</option>
                <option>VIP / Rush</option>
                <option>Standard</option>
              </select>
            </div>
          </QuickFilterPanel>

          <MainContent>
            <OperationalSnapshot>
              <SnapshotCard value={housekeepingData.snapshots.dirty} label="Dirty" statusColor="warning-amber" />
              <SnapshotCard value={housekeepingData.snapshots.pendingInspection} label="Pending Inspection" statusColor="inspection-blue" />
              <SnapshotCard value={housekeepingData.snapshots.cleanReady} label="Clean / Ready" statusColor="ready-green" />
              <SnapshotCard value={housekeepingData.snapshots.rushVip} label="Rush / VIP" statusColor="vip-gold" />
            </OperationalSnapshot>

            <BoardHeader title="Room Task Board">
              <Button variant="primary" size="sm">Auto-Assign Attendants</Button>
            </BoardHeader>

            <WorkBoard>
              {housekeepingData.columns.map((column) => (
                <BoardColumn key={column.id} title={column.title} count={column.count}>
                  {column.floorGroups.map((group, gIdx) => (
                    <React.Fragment key={gIdx}>
                      {group.floor && (
                        <div style={{
                          fontSize: '11px',
                          fontWeight: 700,
                          color: 'var(--text-secondary)',
                          textTransform: 'uppercase',
                          margin: gIdx === 0 ? '4px 0 6px 4px' : '12px 0 6px 4px',
                          letterSpacing: '0.5px'
                        }}>
                          {group.floor}
                        </div>
                      )}
                      
                      {group.cards.map((card) => (
                        <WorkCard
                          key={card.id}
                          borderColor={card.borderColor}
                          meta={
                            <>
                              {card.meta.map((m, mIdx) => (
                                <span key={mIdx}>{m}</span>
                              ))}
                              {card.id === 'wc-2' && ( // Special handling for the VIP badge seen in prototype
                                <StatusBadge status="vip">Rush — VIP Due</StatusBadge>
                              )}
                            </>
                          }
                          title={card.title}
                          detail={card.detail}
                          actions={
                            card.actions && card.actions.map((act, aIdx) => (
                              <Button key={aIdx} variant={act.isPrimary ? 'primary' : 'secondary'} size="sm">
                                {act.label}
                              </Button>
                            ))
                          }
                        />
                      ))}
                    </React.Fragment>
                  ))}
                </BoardColumn>
              ))}
            </WorkBoard>
          </MainContent>
        </SplitLayout>
      </div>
    </>
  );
};

HousekeepingWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default HousekeepingWorkspace;
