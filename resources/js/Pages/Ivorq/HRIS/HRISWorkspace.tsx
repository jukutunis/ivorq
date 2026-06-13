import React, { useState } from 'react';
import '../../../../css/ivorq-prototype.css';

import { hrisData } from '../../../data/ivorq/hris';

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
import StatusBadge, { BadgeStatus } from '../../../Components/Ivorq/primitives/StatusBadge';
import Avatar from '../../../Components/Ivorq/primitives/Avatar';

const HRISWorkspace = () => {
  const [activeTab, setActiveTab] = useState('attendance');

  const tabs = [
    { id: 'attendance', label: 'Attendance' },
    { id: 'shift_coverage', label: 'Shift Coverage' },
    { id: 'leave_requests', label: 'Leave Requests', badge: 3 },
    { id: 'payroll', label: 'Payroll' },
  ];

  return (
    <>
      <div className="workspace">
        <WorkspaceHeader title="HRIS" />

        <ModuleTabs tabs={tabs} activeTabId={activeTab} onTabChange={setActiveTab} />

        <SplitLayout>
          <QuickFilterPanel>
            <div className="filter-group">
              <label className="filter-label">Department</label>
              <select className="filter-input">
                <option>All</option>
                <option>Front Office</option>
                <option>Housekeeping</option>
                <option>Engineering</option>
                <option>F&B</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Position</label>
              <select className="filter-input">
                <option>All</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Shift</label>
              <select className="filter-input">
                <option>Current Shift</option>
                <option>Morning</option>
                <option>Afternoon</option>
                <option>Night</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Employment Status</label>
              <select className="filter-input">
                <option>Active</option>
                <option>Probation</option>
                <option>On Leave</option>
              </select>
            </div>
          </QuickFilterPanel>

          <MainContent>
            <OperationalSnapshot>
              <SnapshotCard value={hrisData.snapshots.clockedIn.value} label="Clocked In" />
              <SnapshotCard value={hrisData.snapshots.lateNoShow.value} label="Late / No-Show" statusColor="critical-red" />
              <SnapshotCard value={hrisData.snapshots.leaveRequests.value} label="Leave Requests" statusColor="warning-amber" />
            </OperationalSnapshot>

            <AttentionArea title="Leave Approvals Needed" badgeType="warning" badgeText="3 Pending" areaType="warning">
              {hrisData.leaveApprovals.map(req => (
                <AttentionCard
                  key={req.id}
                  title={
                    <span style={{ fontSize: '13px' }}>
                      {req.employee} — {req.type}
                    </span>
                  }
                  meta={`${req.department} • ${req.dates} (${req.duration})`}
                  actions={
                    <>
                      <Button variant="secondary" size="sm">Decline</Button>
                      <Button variant="primary" size="sm">Approve</Button>
                    </>
                  }
                />
              ))}
            </AttentionArea>

            <QueueList
              title="Workforce Status — Afternoon Shift"
              actions={<Button variant="secondary" size="sm">Manage Roster</Button>}
            >
              <div style={{ padding: '12px 20px', background: 'var(--surface-page)', borderBottom: '1px solid var(--border-subtle)', fontSize: '11px', fontWeight: 700, color: 'var(--text-secondary)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                Front Office
              </div>
              {hrisData.workforce.filter(emp => emp.department === 'Front Office').map(emp => (
                <QueueItem
                  key={emp.id}
                  avatar={
                    <Avatar 
                      initials={emp.initials} 
                      bgColor={emp.avatarBg} 
                      color={emp.avatarColor} 
                    />
                  }
                  title={
                    <>
                      {emp.name}
                      <div style={{ display: 'inline-block', width: '6px', height: '6px', borderRadius: '50%', background: `var(--${emp.statusDot})`, marginLeft: '4px', verticalAlign: 'middle' }}></div>
                    </>
                  }
                  meta={emp.position}
                  actions={
                    <StatusBadge status={emp.badge.status as BadgeStatus}>{emp.badge.label}</StatusBadge>
                  }
                />
              ))}

              <div style={{ padding: '12px 20px', background: 'var(--surface-page)', borderBottom: '1px solid var(--border-subtle)', fontSize: '11px', fontWeight: 700, color: 'var(--text-secondary)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                Housekeeping
              </div>
              {hrisData.workforce.filter(emp => emp.department === 'Housekeeping').map(emp => (
                <QueueItem
                  key={emp.id}
                  avatar={
                    <Avatar 
                      initials={emp.initials} 
                      bgColor={emp.avatarBg} 
                      color={emp.avatarColor} 
                    />
                  }
                  title={
                    <>
                      {emp.name}
                      <div style={{ display: 'inline-block', width: '6px', height: '6px', borderRadius: '50%', background: `var(--${emp.statusDot})`, marginLeft: '4px', verticalAlign: 'middle' }}></div>
                    </>
                  }
                  meta={emp.position}
                  actions={
                    <StatusBadge status={emp.badge.status as BadgeStatus}>{emp.badge.label}</StatusBadge>
                  }
                />
              ))}
            </QueueList>
          </MainContent>
        </SplitLayout>
      </div>
    </>
  );
};

HRISWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default HRISWorkspace;
