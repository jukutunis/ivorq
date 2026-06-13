import React, { useState } from 'react';
import '../../../../css/ivorq-prototype.css';

import { engineeringData } from '../../../data/ivorq/engineering';

import AppTopbar from '../../../Components/Ivorq/shell/AppTopbar';
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
import Icon from '../../../Components/Ivorq/primitives/Icon';
import StatusBadge, { BadgeStatus } from '../../../Components/Ivorq/primitives/StatusBadge';

export default function EngineeringWorkspace() {
  const [activeTab, setActiveTab] = useState('work_orders');

  const tabs = [
    { id: 'work_orders', label: 'Work Orders', badge: 12 },
    { id: 'pm_schedule', label: 'PM Schedule', badge: 5 },
    { id: 'assets', label: 'Assets' },
    { id: 'incidents', label: 'Incidents' },
  ];

  return (
    <>
      <AppTopbar />
      <div className="workspace">
        <WorkspaceHeader title="Engineering">
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
              <label className="filter-label">Priority</label>
              <select className="filter-input">
                <option>All</option>
                <option>Critical</option>
                <option>High</option>
                <option>Medium</option>
                <option>Low</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Location</label>
              <select className="filter-input">
                <option>All</option>
                <option>Guest Rooms</option>
                <option>Public Areas</option>
                <option>Back of House</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Asset</label>
              <input type="text" className="filter-input" placeholder="Search asset..." />
            </div>
            <div className="filter-group">
              <label className="filter-label">Technician</label>
              <select className="filter-input">
                <option>All</option>
                <option>Wayan Bayu</option>
                <option>Ketut Adi</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Status</label>
              <select className="filter-input">
                <option>All Open</option>
                <option>Unassigned</option>
                <option>In Progress</option>
                <option>On Hold</option>
              </select>
            </div>
          </QuickFilterPanel>

          <MainContent>
            <OperationalSnapshot>
              <SnapshotCard value={engineeringData.snapshots.slaBreached} label="SLA Breached" statusColor="critical-red" />
              <SnapshotCard value={engineeringData.snapshots.openWorkOrders} label="Open Work Orders" />
              <SnapshotCard value={engineeringData.snapshots.pmDueThisWeek} label="PM Due This Week" statusColor="warning-amber" />
            </OperationalSnapshot>

            <AttentionArea title="SLA Attention Required" badgeType="critical" badgeText="2 Overdue" areaType="none">
              {engineeringData.attentionItems.map(item => (
                <AttentionCard
                  key={item.id}
                  title={item.title}
                  meta={item.meta}
                  actions={
                    item.actions.map((act, i) => (
                      <Button key={i} variant={act.isPrimary ? 'primary' : 'secondary'} size="sm">
                        {act.label}
                      </Button>
                    ))
                  }
                />
              ))}
            </AttentionArea>

            <QueueList
              title={<><Icon name="warning" /> Guest Impact Work</>}
              style={{ marginBottom: '20px', borderColor: '#FECACA' }}
              headerStyle={{ background: 'var(--critical-red-bg)', borderBottomColor: '#FECACA', color: 'var(--critical-red)' }}
              actions={<Button variant="primary" size="sm">Create Work Order</Button>}
            >
              {engineeringData.guestImpactWork.map(task => (
                <QueueItem
                  key={task.id}
                  style={task.style}
                  title={task.title}
                  meta={task.meta}
                  actions={
                    <>
                      {task.badge && <StatusBadge status={task.badge.status as BadgeStatus}>{task.badge.label}</StatusBadge>}
                      {task.actions?.map((act, i) => (
                        <Button key={i} variant={act.isPrimary ? 'primary' : 'secondary'} size="sm">{act.label}</Button>
                      ))}
                    </>
                  }
                />
              ))}
            </QueueList>

            <QueueList
              title="Back Of House Work"
              actions={<Button variant="secondary" size="sm">Create PM Task</Button>}
            >
              {engineeringData.backOfHouseWork.map(task => (
                <QueueItem
                  key={task.id}
                  title={task.title}
                  meta={task.meta}
                  actions={
                    <>
                      {task.badge && <StatusBadge status={task.badge.status as BadgeStatus}>{task.badge.label}</StatusBadge>}
                      {task.badge2 && <StatusBadge status={task.badge2.status as BadgeStatus}>{task.badge2.label}</StatusBadge>}
                      {task.actions?.map((act, i) => (
                        <Button key={i} variant={act.isPrimary ? 'primary' : 'secondary'} size="sm">{act.label}</Button>
                      ))}
                    </>
                  }
                />
              ))}
            </QueueList>
          </MainContent>
        </SplitLayout>
      </div>
    </>
  );
}
