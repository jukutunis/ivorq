import React from 'react';
import '../../../../css/ivorq-prototype.css';
import { usePage, router } from '@inertiajs/react';
import axios from 'axios';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import QueueList from '../../../Components/Ivorq/patterns/QueueList';
import QueueItem from '../../../Components/Ivorq/patterns/QueueItem';

import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';
import StatusBadge, { BadgeStatus } from '../../../Components/Ivorq/primitives/StatusBadge';

interface EngineeringWorkspaceProps {
  activeTab?: string;
  workOrders?: any[];
  technicians?: any[];
}

const EngineeringWorkspace = ({
  activeTab = 'work_orders',
  workOrders = [],
  technicians = []
}: EngineeringWorkspaceProps) => {
  const { props } = usePage();
  const auth = (props.auth as any) || {};
  const currentPropertyId = auth.user?.property_id;

  const tabs = [
    { href: '/engineering/work-orders', label: 'Work Orders', badge: workOrders.filter(w => w.status !== 'closed').length },
    { href: '/engineering/preventive-maintenance', label: 'Preventive Maintenance' },
    { href: '/engineering/asset-registry', label: 'Asset Registry' },
    { href: '/engineering/technician-schedule', label: 'Technician Schedule' },
  ];

  // Filters State
  const [filterPriority, setFilterPriority] = React.useState('All');
  const [filterStatus, setFilterStatus] = React.useState('All Open');

  // Creation State
  const [showCreate, setShowCreate] = React.useState(false);
  const [title, setTitle] = React.useState('');
  const [description, setDescription] = React.useState('');
  const [priority, setPriority] = React.useState('medium');
  const [type, setType] = React.useState('corrective');
  const [hasGuestImpact, setHasGuestImpact] = React.useState(false);

  // Actions State
  const [assigneeId, setAssigneeId] = React.useState<Record<string, string>>({});
  const [resNotes, setResNotes] = React.useState<Record<string, string>>({});

  const handleCreate = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title) return;

    axios.post('/api/v1/operations/work-orders', {
      title,
      description,
      priority,
      type,
      has_guest_impact: hasGuestImpact,
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      setShowCreate(false);
      setTitle('');
      setDescription('');
      setPriority('medium');
      setType('corrective');
      setHasGuestImpact(false);
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error creating work order');
    });
  };

  const handleAssign = (woId: string) => {
    const userId = assigneeId[woId];
    if (!userId) {
      alert('Please select a technician.');
      return;
    }

    axios.post(`/api/v1/operations/work-orders/${woId}/assignments`, {
      user_id: userId
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error assigning technician');
    });
  };

  const handleStartWork = (woId: string) => {
    axios.patch(`/api/v1/operations/work-orders/${woId}/status`, {
      status: 'in_progress'
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error starting work');
    });
  };

  const handleResolve = (woId: string) => {
    const notes = resNotes[woId];
    if (!notes || notes.trim() === '') {
      alert('Resolution notes are required.');
      return;
    }

    axios.patch(`/api/v1/operations/work-orders/${woId}/status`, {
      status: 'resolved',
      resolution_notes: notes
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error resolving work order');
    });
  };

  const handleClose = (woId: string, notes: string) => {
    axios.post(`/api/v1/operations/work-orders/${woId}/closures`, {
      resolution_notes: notes || 'Verified and closed.'
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error closing work order');
    });
  };

  // Filter logic
  const filteredWorkOrders = workOrders.filter(wo => {
    if (filterPriority !== 'All' && wo.priority !== filterPriority.toLowerCase()) {
      return false;
    }
    if (filterStatus === 'All Open' && wo.status === 'closed') {
      return false;
    }
    if (filterStatus === 'Unassigned' && (wo.status !== 'draft' && wo.status !== 'open')) {
      return false;
    }
    if (filterStatus === 'In Progress' && wo.status !== 'in_progress') {
      return false;
    }
    return true;
  });

  const guestImpactWork = filteredWorkOrders.filter(w => w.has_guest_impact);
  const backOfHouseWork = filteredWorkOrders.filter(w => !w.has_guest_impact);

  const getPriorityBadgeStatus = (p: string): BadgeStatus => {
    switch (p) {
      case 'emergency': return 'critical';
      case 'high': return 'warning';
      case 'medium': return 'info';
      default: return 'success';
    }
  };

  const getStatusBadgeStatus = (s: string): BadgeStatus => {
    switch (s) {
      case 'resolved':
      case 'closed': return 'success';
      case 'cancelled': return 'error';
      default: return 'info';
    }
  };

  const renderQueueItem = (wo: any) => {
    const activeAssignment = wo.assignments?.find((a: any) => a.status === 'active');
    const assignedUser = activeAssignment?.user;
    const isAssignedToMe = assignedUser?.id === auth.user?.id;

    return (
      <QueueItem
        key={wo.id}
        title={`${wo.wo_number}: ${wo.title}`}
        meta={
          <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', fontSize: '13px', color: '#64748B' }}>
            {wo.description && <div>{wo.description}</div>}
            <div style={{ display: 'flex', gap: '12px', alignItems: 'center' }}>
              <span>Type: <strong>{wo.type}</strong></span>
              <span>Assigned to: <strong>{assignedUser?.name || 'Unassigned'}</strong></span>
            </div>
          </div>
        }
        actions={
          <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
            <StatusBadge status={getPriorityBadgeStatus(wo.priority)}>{wo.priority.toUpperCase()}</StatusBadge>
            <StatusBadge status={getStatusBadgeStatus(wo.status)}>{wo.status.toUpperCase()}</StatusBadge>

            {/* Supervisor Assignment Flow */}
            {(wo.status === 'draft' || wo.status === 'open') && (
              <div style={{ display: 'flex', gap: '4px', alignItems: 'center' }}>
                <select
                  className="filter-input"
                  style={{ padding: '2px 8px', fontSize: '12px', height: '30px' }}
                  value={assigneeId[wo.id] || ''}
                  onChange={(e) => setAssigneeId({ ...assigneeId, [wo.id]: e.target.value })}
                >
                  <option value="">Select Tech...</option>
                  {technicians.map(t => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                  ))}
                </select>
                <Button variant="primary" size="sm" onClick={() => handleAssign(wo.id)}>Assign</Button>
              </div>
            )}

            {/* Technician Start Flow */}
            {wo.status === 'assigned' && isAssignedToMe && (
              <Button variant="primary" size="sm" onClick={() => handleStartWork(wo.id)}>Start Work</Button>
            )}

            {/* Technician Resolve Flow */}
            {wo.status === 'in_progress' && isAssignedToMe && (
              <div style={{ display: 'flex', gap: '4px', alignItems: 'center' }}>
                <input
                  type="text"
                  className="filter-input"
                  placeholder="Resolution notes..."
                  style={{ padding: '2px 8px', fontSize: '12px', height: '30px', width: '150px' }}
                  value={resNotes[wo.id] || ''}
                  onChange={(e) => setResNotes({ ...resNotes, [wo.id]: e.target.value })}
                />
                <Button variant="primary" size="sm" onClick={() => handleResolve(wo.id)}>Resolve</Button>
              </div>
            )}

            {/* Supervisor Close Flow */}
            {wo.status === 'resolved' && (
              <Button variant="success" size="sm" onClick={() => handleClose(wo.id, wo.histories?.find((h: any) => h.action === 'resolved')?.description)}>
                Verify & Close
              </Button>
            )}
          </div>
        }
      />
    );
  };

  return (
    <>
      <div className="workspace">
        <WorkspaceHeader title="Engineering">
          <Button variant="primary" onClick={() => setShowCreate(!showCreate)}>
            <Icon name="plus" /> Create Engineering Request
          </Button>
        </WorkspaceHeader>

        <ModuleTabs tabs={tabs} />

        <SplitLayout>
          <QuickFilterPanel>
            <div className="filter-group">
              <label className="filter-label">Priority</label>
              <select className="filter-input" value={filterPriority} onChange={(e) => setFilterPriority(e.target.value)}>
                <option>All</option>
                <option>Emergency</option>
                <option>High</option>
                <option>Medium</option>
                <option>Low</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Status</label>
              <select className="filter-input" value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
                <option>All Open</option>
                <option>Unassigned</option>
                <option>In Progress</option>
              </select>
            </div>
          </QuickFilterPanel>

          <MainContent>
            {showCreate && (
              <div style={{ background: '#F8FAFC', border: '1px solid #E2E8F0', padding: '16px', borderRadius: '8px', marginBottom: '20px' }}>
                <h3 style={{ marginBottom: '12px', fontSize: '16px', fontWeight: '600' }}>Create Engineering Request</h3>
                <form onSubmit={handleCreate} style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                    <div>
                      <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>Title</label>
                      <input type="text" className="filter-input" style={{ width: '100%' }} value={title} onChange={e => setTitle(e.target.value)} required />
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>Type</label>
                      <select className="filter-input" style={{ width: '100%' }} value={type} onChange={e => setType(e.target.value)}>
                        <option value="corrective">Corrective</option>
                        <option value="reactive">Reactive</option>
                        <option value="emergency">Emergency</option>
                        <option value="breakdown">Breakdown</option>
                      </select>
                    </div>
                  </div>
                  <div>
                    <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>Description</label>
                    <textarea className="filter-input" style={{ width: '100%', height: '60px' }} value={description} onChange={e => setDescription(e.target.value)} />
                  </div>
                  <div style={{ display: 'flex', gap: '24px', alignItems: 'center' }}>
                    <div>
                      <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>Priority</label>
                      <select className="filter-input" value={priority} onChange={e => setPriority(e.target.value)}>
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                        <option value="emergency">Emergency</option>
                      </select>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginTop: '16px' }}>
                      <input type="checkbox" id="guestImpact" checked={hasGuestImpact} onChange={e => setHasGuestImpact(e.target.checked)} />
                      <label htmlFor="guestImpact" style={{ fontSize: '13px' }}>Guest Impact</label>
                    </div>
                  </div>
                  <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
                    <Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button>
                    <Button variant="primary" type="submit">Submit Request</Button>
                  </div>
                </form>
              </div>
            )}

            <OperationalSnapshot>
              <SnapshotCard value={workOrders.filter(w => w.status === 'resolved').length} label="Resolved / Verification Queue" statusColor="warning-amber" />
              <SnapshotCard value={workOrders.filter(w => w.status !== 'closed').length} label="Active Work Orders" />
              <SnapshotCard value={workOrders.filter(w => w.status === 'closed').length} label="Completed / Closed" statusColor="success-green" />
            </OperationalSnapshot>

            {guestImpactWork.length > 0 && (
              <QueueList
                title={<><Icon name="warning" /> Guest Impact Work</>}
                style={{ marginBottom: '20px', borderColor: '#FECACA' }}
                headerStyle={{ background: 'var(--critical-red-bg)', borderBottomColor: '#FECACA', color: 'var(--critical-red)' }}
              >
                {guestImpactWork.map(renderQueueItem)}
              </QueueList>
            )}

            <QueueList title="Back Of House Work">
              {backOfHouseWork.length > 0 ? (
                backOfHouseWork.map(renderQueueItem)
              ) : (
                <div style={{ padding: '20px', textAlign: 'center', color: '#64748B' }}>No active back of house work orders.</div>
              )}
            </QueueList>
          </MainContent>
        </SplitLayout>
      </div>
    </>
  );
};

EngineeringWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default EngineeringWorkspace;
