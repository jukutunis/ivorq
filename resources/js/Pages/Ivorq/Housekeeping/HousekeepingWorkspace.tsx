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

import BoardHeader from '../../../Components/Ivorq/housekeeping/BoardHeader';
import WorkBoard from '../../../Components/Ivorq/housekeeping/WorkBoard';
import BoardColumn from '../../../Components/Ivorq/housekeeping/BoardColumn';
import WorkCard from '../../../Components/Ivorq/housekeeping/WorkCard';

import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';
import StatusBadge from '../../../Components/Ivorq/primitives/StatusBadge';

interface HousekeepingWorkspaceProps {
  activeTab?: string;
  rooms?: any[];
  tasks?: any[];
  attendants?: any[];
  readinessRows?: any[];
  auth_user?: any;
}

const HousekeepingWorkspace = ({
  activeTab = 'room_board',
  rooms = [],
  tasks = [],
  attendants = [],
  readinessRows = [],
  auth_user = null
}: HousekeepingWorkspaceProps) => {
  const { props } = usePage();
  const auth = (props.auth as any) || {};
  const currentPropertyId = auth_user?.property_id || auth.user?.property_id;

  const tabs = [
    { href: '/housekeeping/room-board', label: 'Room Board', badge: tasks.filter(t => t.status !== 'completed' || !t.verified_at).length },
    { href: '/housekeeping/attendant-status', label: 'Attendant Status' },
    { href: '/housekeeping/inspections', label: 'Inspections', badge: tasks.filter(t => t.status === 'completed' && !t.verified_at).length },
    { href: '/housekeeping/room-readiness', label: 'Room Readiness' },
    { href: '/housekeeping/lost-found', label: 'Lost & Found' },
  ];

  // Actions State
  const [selectedAttendant, setSelectedAttendant] = React.useState<Record<string, string>>({});

  // Create task state
  const [showCreate, setShowCreate] = React.useState(false);
  const [createRoomId, setCreateRoomId] = React.useState('');
  const [createTitle, setCreateTitle] = React.useState('General Clean');
  const [createTaskType, setCreateTaskType] = React.useState('checkout_cleaning');

  // Filters State
  const [filterFloor, setFilterFloor] = React.useState('All');
  const [filterCleanliness, setFilterCleanliness] = React.useState('All');

  // Snapshot calculations
  const snapshotDirty = rooms.filter(r => r.cleanliness_status === 'dirty').length;
  const snapshotPendingInspection = tasks.filter(t => t.status === 'completed' && !t.verified_at).length;
  const snapshotCleanReady = rooms.filter(r => r.cleanliness_status === 'inspected').length;
  const snapshotRushVip = tasks.filter(t => (t.priority === 'rush' || t.room?.is_vip) && (!t.completed_at)).length;

  const handleCreateTask = (e: React.FormEvent) => {
    e.preventDefault();
    if (!createRoomId) {
      alert("Please select a room.");
      return;
    }

    const taskCode = `HK-${Date.now().toString().slice(-6)}`;

    axios.post('/operations/cleaning-tasks', {
      room_id: createRoomId,
      title: createTitle,
      task_type: createTaskType,
      task_code: taskCode,
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      setShowCreate(false);
      setCreateRoomId('');
      setCreateTitle('General Clean');
      setCreateTaskType('checkout_cleaning');
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error creating housekeeping task');
    });
  };

  const handleAssign = (taskId: string) => {
    const attendantId = selectedAttendant[taskId];
    if (!attendantId) {
      alert("Please select an attendant.");
      return;
    }
    const attendant = attendants.find((a: any) => a.id === attendantId);

    axios.post(`/operations/cleaning-tasks/${taskId}/assign`, {
      user_id: attendantId,
      department_id: attendant?.department_id || 'default-department-id',
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error assigning attendant');
    });
  };

  const handleStartCleaning = (taskId: string) => {
    axios.post(`/operations/cleaning-tasks/${taskId}/status`, {
      status: 'in_progress',
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error starting cleaning');
    });
  };

  const handleCompleteCleaning = (taskId: string) => {
    const note = prompt("Enter completion notes (required):");
    if (note === null) return;
    if (note.trim() === '') {
      alert("Completion note is required.");
      return;
    }

    axios.post(`/operations/cleaning-tasks/${taskId}/status`, {
      status: 'completed',
      remarks: note,
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error completing cleaning');
    });
  };

  const handlePassInspection = (inspectionId: string) => {
    const remarks = prompt("Enter optional inspection remarks:") || "";

    axios.post(`/operations/inspections/${inspectionId}/pass`, {
      remarks: remarks,
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error passing inspection');
    });
  };

  const handleFailInspection = (inspectionId: string) => {
    const remarks = prompt("Enter inspection failure remarks (required):");
    if (remarks === null) return;
    if (remarks.trim() === '') {
      alert("Failure remarks are required.");
      return;
    }

    axios.post(`/operations/inspections/${inspectionId}/fail`, {
      remarks: remarks,
    }, {
      headers: {
        'X-Property-ID': currentPropertyId,
      }
    }).then(() => {
      router.reload();
    }).catch(err => {
      alert(err.response?.data?.message || 'Error failing inspection');
    });
  };

  // Grouping tasks by floor helper
  const groupTasksByFloor = (columnTasks: any[]) => {
    const floorsMap: Record<string, any[]> = {};
    columnTasks.forEach(task => {
      if (filterFloor !== 'All' && String(task.room?.floor) !== filterFloor) {
        return;
      }
      if (filterCleanliness !== 'All' && task.room?.cleanliness_status !== filterCleanliness.toLowerCase()) {
        return;
      }

      const floorName = task.room?.floor ? `Floor ${task.room.floor}` : 'Other';
      if (!floorsMap[floorName]) {
        floorsMap[floorName] = [];
      }
      floorsMap[floorName].push(task);
    });

    return Object.keys(floorsMap).sort().map(floor => ({
      floor,
      cards: floorsMap[floor]
    }));
  };

  const renderCard = (task: any) => {
    const activeAssignment = task.assignments?.find((a: any) => a.status === 'active');
    const assignedUser = activeAssignment?.user;

    let borderColor = 'warning-amber';
    if (task.room?.is_vip || task.priority === 'rush') {
      borderColor = 'vip-gold';
    } else if (task.status === 'in_progress') {
      borderColor = 'inspection-blue';
    } else if (task.status === 'completed' && task.verified_at) {
      borderColor = 'ready-green';
    }

    let meta: React.ReactNode[] = [];
    if (task.status === 'pending') {
      meta.push(<span key="type">{task.task_type?.toUpperCase()}</span>);
    } else if (task.status === 'assigned' || task.status === 'in_progress') {
      meta.push(<span key="user">{assignedUser?.name || 'Unassigned'}</span>);
    } else if (task.status === 'completed' && !task.verified_at) {
      meta.push(<span key="user">Cleaned by: {assignedUser?.name || 'Attendant'}</span>);
    } else if (task.status === 'completed' && task.verified_at) {
      meta.push(<span key="inspected">Inspected</span>);
    }

    const title = `Room ${task.room?.room_number || 'N/A'} — ${task.room?.room_type || 'Standard'}`;

    let detail = '';
    if (task.status === 'pending') {
      detail = task.title || 'Departure Clean';
    } else if (task.status === 'assigned') {
      detail = `Assigned: ${activeAssignment?.assigned_at ? new Date(activeAssignment.assigned_at).toLocaleTimeString() : 'N/A'}`;
    } else if (task.status === 'in_progress') {
      detail = `Started: ${task.started_at ? new Date(task.started_at).toLocaleTimeString() : 'N/A'}`;
    } else if (task.status === 'completed' && !task.verified_at) {
      detail = `Completed: ${task.completed_at ? new Date(task.completed_at).toLocaleTimeString() : 'N/A'}`;
    } else if (task.status === 'completed' && task.verified_at) {
      detail = `Ready: ${task.verified_at ? new Date(task.verified_at).toLocaleTimeString() : 'N/A'}`;
    }

    let actions: React.ReactNode = null;
    if (task.status === 'pending') {
      actions = (
        <div style={{ display: 'flex', gap: '4px', alignItems: 'center', width: '100%', marginTop: '8px' }}>
          <select
            className="filter-input"
            style={{ padding: '2px 8px', fontSize: '11px', height: '28px', flex: 1 }}
            value={selectedAttendant[task.id] || ''}
            onChange={(e) => setSelectedAttendant({ ...selectedAttendant, [task.id]: e.target.value })}
          >
            <option value="">Attendant...</option>
            {attendants.map((a: any) => (
              <option key={a.id} value={a.id}>{a.name}</option>
            ))}
          </select>
          <Button variant="primary" size="sm" onClick={() => handleAssign(task.id)}>
            Assign
          </Button>
        </div>
      );
    } else if (task.status === 'assigned') {
      const isAssignedToMe = assignedUser?.id === auth_user?.id;
      if (isAssignedToMe) {
        actions = (
          <div style={{ marginTop: '8px' }}>
            <Button variant="primary" size="sm" onClick={() => handleStartCleaning(task.id)}>
              Start Cleaning
            </Button>
          </div>
        );
      }
    } else if (task.status === 'in_progress') {
      const isAssignedToMe = assignedUser?.id === auth_user?.id;
      if (isAssignedToMe) {
        actions = (
          <div style={{ marginTop: '8px' }}>
            <Button variant="primary" size="sm" onClick={() => handleCompleteCleaning(task.id)}>
              Complete
            </Button>
          </div>
        );
      }
    } else if (task.status === 'completed' && !task.verified_at) {
      const pendingInspection = task.inspections?.find((i: any) => i.status === 'pending');
      if (pendingInspection) {
        actions = (
          <div style={{ display: 'flex', gap: '4px', marginTop: '8px', width: '100%' }}>
            <Button variant="success" size="sm" style={{ flex: 1 }} onClick={() => handlePassInspection(pendingInspection.id)}>
              Pass
            </Button>
            <Button variant="danger" size="sm" style={{ flex: 1 }} onClick={() => handleFailInspection(pendingInspection.id)}>
              Fail
            </Button>
          </div>
        );
      }
    }

    return (
      <WorkCard
        key={task.id}
        borderColor={borderColor}
        meta={
          <>
            {meta}
            {(task.room?.is_vip || task.priority === 'rush') && (
              <StatusBadge status="vip">Rush — VIP Due</StatusBadge>
            )}
          </>
        }
        title={title}
        detail={detail}
        actions={actions}
      />
    );
  };

  const boardColumns = [
    {
      id: 'pending',
      title: 'New Room Task',
      tasks: tasks.filter(t => t.status === 'pending')
    },
    {
      id: 'assigned',
      title: 'Assigned',
      tasks: tasks.filter(t => t.status === 'assigned')
    },
    {
      id: 'in-progress',
      title: 'Cleaning In Progress',
      tasks: tasks.filter(t => t.status === 'in_progress')
    },
    {
      id: 'awaiting-inspection',
      title: 'Awaiting Inspection',
      tasks: tasks.filter(t => t.status === 'completed' && !t.verified_at)
    },
    {
      id: 'ready',
      title: 'Ready',
      tasks: tasks.filter(t => t.status === 'completed' && t.verified_at)
    }
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

        <ModuleTabs tabs={tabs} />

        <SplitLayout>
          {activeTab === 'room_readiness' ? (
            <>
              <QuickFilterPanel>
                <div className="filter-group">
                  <label className="filter-label">Readiness Overview</label>
                  <p style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
                    Housekeeping room readiness status for Front Desk assignment eligibility.
                  </p>
                </div>
              </QuickFilterPanel>
              <MainContent>
                <OperationalSnapshot>
                  <SnapshotCard
                    value={readinessRows.filter((r: any) => r.readiness_state === 'ready_for_sale' || r.readiness_state === 'ready_for_arrival' || r.readiness_state === 'ready_for_vip').length}
                    label="Ready"
                    statusColor="ready-green"
                  />
                  <SnapshotCard
                    value={readinessRows.filter((r: any) => r.readiness_state === 'waiting_cleaning' || r.readiness_state === 'cleaning').length}
                    label="Cleaning / Dirty"
                    statusColor="warning-amber"
                  />
                  <SnapshotCard
                    value={readinessRows.filter((r: any) => r.readiness_state === 'waiting_inspection').length}
                    label="Pending Inspection"
                    statusColor="inspection-blue"
                  />
                  <SnapshotCard
                    value={readinessRows.filter((r: any) => r.readiness_state === 'blocked').length}
                    label="Blocked"
                    statusColor="critical"
                  />
                </OperationalSnapshot>

                <div style={{ background: '#fff', borderRadius: '8px', border: '1px solid #E2E8F0', overflow: 'hidden' }}>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13px' }}>
                    <thead>
                      <tr style={{ background: '#F8FAFC', borderBottom: '1px solid #E2E8F0' }}>
                        <th style={{ padding: '10px 16px', textAlign: 'left', fontWeight: 600, color: 'var(--text-secondary)', fontSize: '11px', textTransform: 'uppercase' }}>Room</th>
                        <th style={{ padding: '10px 16px', textAlign: 'left', fontWeight: 600, color: 'var(--text-secondary)', fontSize: '11px', textTransform: 'uppercase' }}>Type</th>
                        <th style={{ padding: '10px 16px', textAlign: 'left', fontWeight: 600, color: 'var(--text-secondary)', fontSize: '11px', textTransform: 'uppercase' }}>Floor/Zone</th>
                        <th style={{ padding: '10px 16px', textAlign: 'left', fontWeight: 600, color: 'var(--text-secondary)', fontSize: '11px', textTransform: 'uppercase' }}>Cleanliness</th>
                        <th style={{ padding: '10px 16px', textAlign: 'left', fontWeight: 600, color: 'var(--text-secondary)', fontSize: '11px', textTransform: 'uppercase' }}>Readiness State</th>
                        <th style={{ padding: '10px 16px', textAlign: 'left', fontWeight: 600, color: 'var(--text-secondary)', fontSize: '11px', textTransform: 'uppercase' }}>Front Desk Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {readinessRows.length === 0 ? (
                        <tr>
                          <td colSpan={6} style={{ padding: '32px 16px', textAlign: 'center', color: 'var(--text-secondary)' }}>
                            No rooms found for this property.
                          </td>
                        </tr>
                      ) : (
                        readinessRows.map((row: any) => {
                          const readyStates = ['ready_for_sale', 'ready_for_arrival', 'ready_for_vip'];
                          const isReady = readyStates.includes(row.readiness_state);
                          const isBlocked = row.readiness_state === 'blocked';

                          return (
                            <tr key={row.id} style={{ borderBottom: '1px solid #F1F5F9' }}>
                              <td style={{ padding: '8px 16px', fontWeight: 500 }}>
                                {row.room_number}
                                {row.is_vip && <StatusBadge status="vip">VIP</StatusBadge>}
                              </td>
                              <td style={{ padding: '8px 16px', color: 'var(--text-secondary)' }}>{row.room_type}</td>
                              <td style={{ padding: '8px 16px', color: 'var(--text-secondary)' }}>{row.floor || '-'} {row.zone ? `/ ${row.zone}` : ''}</td>
                              <td style={{ padding: '8px 16px' }}>
                                <StatusBadge status={
                                  row.cleanliness_status === 'inspected' ? 'success' :
                                  row.cleanliness_status === 'clean' ? 'info' :
                                  row.cleanliness_status === 'dirty' ? 'warning' :
                                  'default'
                                }>{row.cleanliness_status}</StatusBadge>
                              </td>
                              <td style={{ padding: '8px 16px', fontSize: '12px' }}>
                                <code style={{ background: '#F1F5F9', padding: '2px 6px', borderRadius: '3px' }}>{row.readiness_state}</code>
                              </td>
                              <td style={{ padding: '8px 16px' }}>
                                {isReady ? (
                                  <StatusBadge status="success">HOUSEKEEPING_READY</StatusBadge>
                                ) : isBlocked ? (
                                  <StatusBadge status="critical">HOUSEKEEPING_BLOCKED</StatusBadge>
                                ) : (
                                  <StatusBadge status="warning">HOUSEKEEPING_BLOCKED</StatusBadge>
                                )}
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>

                <div style={{ marginTop: '16px', padding: '12px', background: '#F8FAFC', borderRadius: '8px', border: '1px solid #E2E8F0', fontSize: '12px', color: 'var(--text-secondary)' }}>
                  <strong style={{ color: 'var(--text-primary)' }}>Readiness Boundary:</strong> Front Desk treats only HOUSEKEEPING_READY rooms as eligible for assignment, check-in, and room move. HOUSEKEEPING_BLOCKED and HOUSEKEEPING_UNKNOWN rooms are treated as blocking. Readiness is Housekeeping-owned and projected read-only to Front Desk.
                </div>
              </MainContent>
            </>
          ) : (
            <>
          <QuickFilterPanel>
            <div className="filter-group">
              <label className="filter-label">Floor / Zone</label>
              <select className="filter-input" value={filterFloor} onChange={e => setFilterFloor(e.target.value)}>
                <option value="All">All Floors</option>
                <option value="1">Floor 1</option>
                <option value="2">Floor 2</option>
                <option value="3">Floor 3</option>
                <option value="4">Floor 4</option>
                <option value="5">Floor 5</option>
                <option value="6">Floor 6</option>
              </select>
            </div>
            <div className="filter-group">
              <label className="filter-label">Room Status</label>
              <select className="filter-input" value={filterCleanliness} onChange={e => setFilterCleanliness(e.target.value)}>
                <option value="All">All</option>
                <option value="Dirty">Dirty</option>
                <option value="Clean">Clean</option>
                <option value="Inspected">Inspected</option>
              </select>
            </div>
          </QuickFilterPanel>

          <MainContent>
            <OperationalSnapshot>
              <SnapshotCard value={snapshotDirty} label="Dirty" statusColor="warning-amber" />
              <SnapshotCard value={snapshotPendingInspection} label="Pending Inspection" statusColor="inspection-blue" />
              <SnapshotCard value={snapshotCleanReady} label="Clean / Ready" statusColor="ready-green" />
              <SnapshotCard value={snapshotRushVip} label="Rush / VIP" statusColor="vip-gold" />
            </OperationalSnapshot>

            <BoardHeader title="Room Task Board">
              <Button variant="primary" size="sm" onClick={() => setShowCreate(!showCreate)}>
                New Room Task
              </Button>
            </BoardHeader>

            {showCreate && (
              <div style={{ background: '#F8FAFC', border: '1px solid #E2E8F0', padding: '16px', borderRadius: '8px', marginBottom: '20px' }}>
                <h3 style={{ marginBottom: '12px', fontSize: '14px', fontWeight: '600' }}>New Room Task</h3>
                <form onSubmit={handleCreateTask} style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '12px' }}>
                    <div>
                      <label style={{ display: 'block', fontSize: '11px', marginBottom: '4px', fontWeight: 'bold' }}>Room</label>
                      <select className="filter-input" style={{ width: '100%' }} value={createRoomId} onChange={e => setCreateRoomId(e.target.value)} required>
                        <option value="">Select Room...</option>
                        {rooms.map((r: any) => (
                          <option key={r.id} value={r.id}>Room {r.room_number} ({r.room_type})</option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: '11px', marginBottom: '4px', fontWeight: 'bold' }}>Title</label>
                      <input type="text" className="filter-input" style={{ width: '100%' }} value={createTitle} onChange={e => setCreateTitle(e.target.value)} required />
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: '11px', marginBottom: '4px', fontWeight: 'bold' }}>Task Type</label>
                      <select className="filter-input" style={{ width: '100%' }} value={createTaskType} onChange={e => setCreateTaskType(e.target.value)}>
                        <option value="checkout_cleaning">Departure</option>
                        <option value="stayover_cleaning">Stayover</option>
                        <option value="turndown">Turndown</option>
                        <option value="deep_cleaning">Deep Clean</option>
                      </select>
                    </div>
                  </div>
                  <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
                    <Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button>
                    <Button variant="primary" type="submit">Submit</Button>
                  </div>
                </form>
              </div>
            )}

            <WorkBoard>
              {boardColumns.map((column) => {
                const floorGroups = groupTasksByFloor(column.tasks);
                const totalCount = floorGroups.reduce((acc, g) => acc + g.cards.length, 0);

                return (
                  <BoardColumn key={column.id} title={column.title} count={totalCount}>
                    {floorGroups.map((group, gIdx) => (
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
                        {group.cards.map(renderCard)}
                      </React.Fragment>
                    ))}
                  </BoardColumn>
                );
              })}
            </WorkBoard>
          </MainContent>
            </>
          )}
        </SplitLayout>
      </div>
    </>
  );
};

HousekeepingWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default HousekeepingWorkspace;
