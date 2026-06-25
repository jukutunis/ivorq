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
import Button from '../../../Components/Ivorq/primitives/Button';
import StatusBadge from '../../../Components/Ivorq/primitives/StatusBadge';

interface ShiftLog {
  id: string;
  subject: string;
  content: string;
  category: string;
  priority: 'low' | 'normal' | 'high';
  status: 'draft' | 'submitted' | 'acknowledged';
  requires_follow_up: boolean;
  created_by: string;
  creator?: { id: string; name: string };
  submitted_by?: string;
  submitter?: { id: string; name: string };
  submitted_at?: string;
  acknowledged_by?: string;
  acknowledged_by_user?: { id: string; name: string };
  acknowledged_at?: string;
  shift_id?: string;
  shift?: { id: string; name: string };
  department_id?: string;
  department?: { id: string; name: string };
  area?: string;
  created_at: string;
}

interface LogbookEntryFollowUpResolution {
  id: string;
  resolution_note: string;
  resolved_by: string;
  resolver?: { id: string; name: string };
  resolved_at: string;
}

interface LogbookEntry {
  id: string;
  subject: string;
  content: string;
  category: string;
  priority: 'low' | 'normal' | 'high';
  status: 'draft' | 'submitted';
  requires_follow_up: boolean;
  created_by: string;
  creator?: { id: string; name: string };
  submitted_by?: string;
  submitter?: { id: string; name: string };
  submitted_at?: string;
  department_id?: string;
  department?: { id: string; name: string };
  created_at: string;
  resolution?: LogbookEntryFollowUpResolution;
}

interface ShiftLogWorkspaceProps {
  shiftLogs: ShiftLog[];
  shifts: any[];
  departments: any[];
  myOperationalEntries?: LogbookEntry[];
  auth_user: any;
}

const ShiftLogWorkspace = ({
  shiftLogs = [],
  shifts = [],
  departments = [],
  myOperationalEntries = [],
  auth_user = null,
}: ShiftLogWorkspaceProps) => {
  const { props } = usePage();
  const auth = (props.auth as any) || {};
  const currentPropertyId = auth_user?.property_id || auth.user?.property_id;

  const tabs = [
    { href: '/logbook', label: 'Shift Logs', badge: shiftLogs.filter(l => l.status === 'submitted').length },
  ];

  // Sub-tab selection state ('handover' | 'operational')
  const [activeSubTab, setActiveSubTab] = React.useState<'handover' | 'operational'>('handover');

  // Form State for Handover
  const [showCreate, setShowCreate] = React.useState(false);

  // Form State for Operational Entry
  const [showOpCreate, setShowOpCreate] = React.useState(false);
  const [editingOpEntry, setEditingOpEntry] = React.useState<LogbookEntry | null>(null);
  const [opSubject, setOpSubject] = React.useState('');
  const [opContent, setOpContent] = React.useState('');
  const [opCategory, setOpCategory] = React.useState('Front Desk');
  const [opPriority, setOpPriority] = React.useState<'low' | 'normal' | 'high'>('normal');
  const [opRequiresFollowUp, setOpRequiresFollowUp] = React.useState(false);
  const [opDepartmentId, setOpDepartmentId] = React.useState('');
  const [resolvingEntryId, setResolvingEntryId] = React.useState<string | null>(null);
  const [resolutionText, setResolutionText] = React.useState('');

  const handleOpEditClick = (entry: LogbookEntry) => {
    setEditingOpEntry(entry);
    setOpSubject(entry.subject);
    setOpContent(entry.content);
    setOpCategory(entry.category);
    setOpPriority(entry.priority);
    setOpRequiresFollowUp(entry.requires_follow_up);
    setOpDepartmentId(entry.department_id || '');
    setShowOpCreate(true);
  };

  const handleOpCancel = () => {
    setShowOpCreate(false);
    setEditingOpEntry(null);
    setOpSubject('');
    setOpContent('');
    setOpCategory('Front Desk');
    setOpPriority('normal');
    setOpRequiresFollowUp(false);
    setOpDepartmentId('');
  };

  const handleOpSubmitForm = (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      subject: opSubject,
      content: opContent,
      category: opCategory,
      priority: opPriority,
      requires_follow_up: opRequiresFollowUp,
      department_id: opDepartmentId || null,
    };

    const request = editingOpEntry 
      ? axios.patch(`/api/v1/operations/logbook-entries/${editingOpEntry.id}`, payload, { headers: { 'X-Property-ID': currentPropertyId } })
      : axios.post('/api/v1/operations/logbook-entries', payload, { headers: { 'X-Property-ID': currentPropertyId } });

    request
      .then(() => {
        handleOpCancel();
        router.reload();
      })
      .catch(err => {
        alert(err.response?.data?.message || 'Error saving entry.');
      });
  };

  const handleOpSubmit = (entryId: string) => {
    axios.post(`/api/v1/operations/logbook-entries/${entryId}/submit`, {}, { headers: { 'X-Property-ID': currentPropertyId } })
      .then(() => {
        router.reload();
      })
      .catch(err => {
        alert(err.response?.data?.message || 'Error submitting entry.');
      });
  };

  const handleResolveFollowUp = (entryId: string) => {
    if (!resolutionText.trim()) {
      alert('Resolution note is required.');
      return;
    }
    axios.post(`/api/v1/operations/logbook-entries/${entryId}/follow-up-resolution`, {
      resolution_note: resolutionText
    }, { headers: { 'X-Property-ID': currentPropertyId } })
      .then(() => {
        setResolvingEntryId(null);
        setResolutionText('');
        router.reload();
      })
      .catch(err => {
        alert(err.response?.data?.message || 'Error resolving follow-up.');
      });
  };
  const [editingLog, setEditingLog] = React.useState<ShiftLog | null>(null);

  const [subject, setSubject] = React.useState('');
  const [content, setContent] = React.useState('');
  const [category, setCategory] = React.useState('Front Desk');
  const [priority, setPriority] = React.useState<'low' | 'normal' | 'high'>('normal');
  const [requiresFollowUp, setRequiresFollowUp] = React.useState(false);
  const [shiftId, setShiftId] = React.useState('');
  const [departmentId, setDepartmentId] = React.useState('');
  const [area, setArea] = React.useState('');

  // Filters State
  const [filterCategory, setFilterCategory] = React.useState('All');
  const [filterFollowUpOnly, setFilterFollowUpOnly] = React.useState(false);

  // Setup form fields for editing
  const handleEditClick = (log: ShiftLog) => {
    setEditingLog(log);
    setSubject(log.subject);
    setContent(log.content);
    setCategory(log.category);
    setPriority(log.priority);
    setRequiresFollowUp(log.requires_follow_up);
    setShiftId(log.shift_id || '');
    setDepartmentId(log.department_id || '');
    setArea(log.area || '');
    setShowCreate(true);
  };

  const handleCancel = () => {
    setShowCreate(false);
    setEditingLog(null);
    setSubject('');
    setContent('');
    setCategory('Front Desk');
    setPriority('normal');
    setRequiresFollowUp(false);
    setShiftId('');
    setDepartmentId('');
    setArea('');
  };

  const handleSubmitForm = (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      subject,
      content,
      category,
      priority,
      requires_follow_up: requiresFollowUp,
      shift_id: shiftId || null,
      department_id: departmentId || null,
      area: area || null,
    };

    const request = editingLog 
      ? axios.patch(`/api/v1/operations/shift-logs/${editingLog.id}`, payload, { headers: { 'X-Property-ID': currentPropertyId } })
      : axios.post('/api/v1/operations/shift-logs', payload, { headers: { 'X-Property-ID': currentPropertyId } });

    request
      .then(() => {
        handleCancel();
        router.reload();
      })
      .catch(err => {
        alert(err.response?.data?.message || 'Error saving shift log.');
      });
  };

  const handleSubmitForHandover = (logId: string) => {
    axios.post(`/api/v1/operations/shift-logs/${logId}/submit`, {}, { headers: { 'X-Property-ID': currentPropertyId } })
      .then(() => {
        router.reload();
      })
      .catch(err => {
        alert(err.response?.data?.message || 'Error submitting handover.');
      });
  };

  const handleAcknowledge = (logId: string) => {
    axios.post(`/api/v1/operations/shift-logs/${logId}/acknowledge`, {}, { headers: { 'X-Property-ID': currentPropertyId } })
      .then(() => {
        router.reload();
      })
      .catch(err => {
        alert(err.response?.data?.message || 'Error acknowledging handover.');
      });
  };

  // Filter logs
  const filteredLogs = shiftLogs.filter(log => {
    if (filterCategory !== 'All' && log.category !== filterCategory) return false;
    if (filterFollowUpOnly && !log.requires_follow_up) return false;
    return true;
  });

  const drafts = filteredLogs.filter(l => l.status === 'draft');
  const submitted = filteredLogs.filter(l => l.status === 'submitted');
  const acknowledged = filteredLogs.filter(l => l.status === 'acknowledged');

  // Categories extraction
  const categoriesList = Array.from(new Set(shiftLogs.map(l => l.category)));

  const renderCard = (log: ShiftLog) => {
    const isCreator = auth_user && log.created_by === auth_user.id;
    const canAcknowledge = log.status === 'submitted' && !isCreator;

    return (
      <div key={log.id} style={{ 
        background: '#ffffff', 
        border: '1px solid #E2E8F0', 
        borderRadius: '8px', 
        padding: '16px', 
        marginBottom: '12px',
        boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.05)',
        display: 'flex',
        flexDirection: 'column',
        gap: '8px'
      }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <h4 style={{ fontSize: '14px', fontWeight: '600', color: '#1E293B', margin: 0 }}>{log.subject}</h4>
          <div style={{ display: 'flex', gap: '4px' }}>
            <span style={{ 
              fontSize: '10px', 
              padding: '2px 6px', 
              borderRadius: '4px', 
              background: log.priority === 'high' ? '#FEE2E2' : log.priority === 'normal' ? '#FEF3C7' : '#F1F5F9',
              color: log.priority === 'high' ? '#991B1B' : log.priority === 'normal' ? '#92400E' : '#475569',
              fontWeight: 'bold',
              textTransform: 'uppercase'
            }}>{log.priority}</span>
            {log.requires_follow_up && (
              <span style={{ 
                fontSize: '10px', 
                padding: '2px 6px', 
                borderRadius: '4px', 
                background: '#EEF2FF', 
                color: '#3730A3', 
                fontWeight: 'bold' 
              }}>Open Follow-up</span>
            )}
          </div>
        </div>

        <p style={{ fontSize: '13px', color: '#475569', margin: 0, whiteSpace: 'pre-wrap' }}>{log.content}</p>

        <div style={{ fontSize: '11px', color: '#64748B', display: 'flex', flexWrap: 'wrap', gap: '8px', borderTop: '1px solid #F1F5F9', paddingTop: '8px', marginTop: '4px' }}>
          <div><strong>Category:</strong> {log.category}</div>
          {log.area && <div><strong>Area:</strong> {log.area}</div>}
          {log.shift && <div><strong>Shift:</strong> {log.shift.name}</div>}
          {log.department && <div><strong>Dept:</strong> {log.department.name}</div>}
        </div>

        <div style={{ fontSize: '11px', color: '#64748B', display: 'flex', flexDirection: 'column', gap: '2px' }}>
          <div><strong>Created By:</strong> {log.creator?.name || 'Unknown'} at {new Date(log.created_at).toLocaleString()}</div>
          {log.submitted_at && (
            <div><strong>Submitted:</strong> {log.submitter?.name || 'Unknown'} at {new Date(log.submitted_at).toLocaleString()}</div>
          )}
          {log.acknowledged_at && (
            <div><strong>Acknowledged:</strong> {log.acknowledged_by_user?.name || log.acknowledged_by || 'Unknown'} at {new Date(log.acknowledged_at).toLocaleString()}</div>
          )}
        </div>

        <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '8px' }}>
          {log.status === 'draft' && isCreator && (
            <>
              <Button variant="secondary" size="sm" onClick={() => handleEditClick(log)}>Edit</Button>
              <Button variant="primary" size="sm" onClick={() => handleSubmitForHandover(log.id)}>Submit Handover</Button>
            </>
          )}
          {canAcknowledge && (
            <Button variant="primary" size="sm" onClick={() => handleAcknowledge(log.id)}>Acknowledge</Button>
          )}
        </div>
      </div>
    );
  };

  const renderOpCard = (entry: LogbookEntry) => {
    const isCreator = auth_user && entry.created_by === auth_user.id;
    const hasResolution = !!entry.resolution;
    const followUpStatus = !entry.requires_follow_up ? 'Not Required' : (hasResolution ? 'Resolved' : 'Open');

    return (
      <div key={entry.id} style={{ 
        background: '#ffffff', 
        border: '1px solid #E2E8F0', 
        borderRadius: '8px', 
        padding: '16px', 
        marginBottom: '12px',
        boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.05)',
        display: 'flex',
        flexDirection: 'column',
        gap: '8px'
      }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <h4 style={{ fontSize: '14px', fontWeight: '600', color: '#1E293B', margin: 0 }}>{entry.subject}</h4>
          <div style={{ display: 'flex', gap: '4px' }}>
            <span style={{ 
              fontSize: '10px', 
              padding: '2px 6px', 
              borderRadius: '4px', 
              background: entry.status === 'submitted' ? '#D1FAE5' : '#F1F5F9',
              color: entry.status === 'submitted' ? '#065F46' : '#475569',
              fontWeight: 'bold',
              textTransform: 'uppercase'
            }}>{entry.status}</span>
            <span style={{ 
              fontSize: '10px', 
              padding: '2px 6px', 
              borderRadius: '4px', 
              background: entry.priority === 'high' ? '#FEE2E2' : entry.priority === 'normal' ? '#FEF3C7' : '#F1F5F9',
              color: entry.priority === 'high' ? '#991B1B' : entry.priority === 'normal' ? '#92400E' : '#475569',
              fontWeight: 'bold',
              textTransform: 'uppercase'
            }}>{entry.priority}</span>
            {entry.requires_follow_up && (
              <span style={{ 
                fontSize: '10px', 
                padding: '2px 6px', 
                borderRadius: '4px', 
                background: hasResolution ? '#E1F5FE' : '#EEF2FF', 
                color: hasResolution ? '#0288D1' : '#3730A3', 
                fontWeight: 'bold' 
              }}>Follow-up Required</span>
            )}
          </div>
        </div>

        <p style={{ fontSize: '13px', color: '#475569', margin: 0, whiteSpace: 'pre-wrap' }}>{entry.content}</p>

        <div style={{ fontSize: '11px', color: '#64748B', display: 'flex', flexWrap: 'wrap', gap: '8px', borderTop: '1px solid #F1F5F9', paddingTop: '8px', marginTop: '4px' }}>
          <div><strong>Category:</strong> {entry.category}</div>
          {entry.department && <div><strong>Dept:</strong> {entry.department.name}</div>}
        </div>

        <div style={{ fontSize: '11px', color: '#64748B', display: 'flex', flexDirection: 'column', gap: '2px' }}>
          <div><strong>Created By:</strong> {entry.creator?.name || 'Unknown'} at {new Date(entry.created_at).toLocaleString()}</div>
          {entry.submitted_at && (
            <div><strong>Submitted:</strong> {entry.submitter?.name || 'Unknown'} at {new Date(entry.submitted_at).toLocaleString()}</div>
          )}
        </div>

        {/* Follow-up Status Section */}
        <div style={{ 
          fontSize: '12px', 
          marginTop: '8px', 
          padding: '8px 12px', 
          borderRadius: '6px', 
          background: followUpStatus === 'Resolved' ? '#F0FDF4' : (followUpStatus === 'Open' ? '#FFFBEB' : '#F8FAFC'),
          border: '1px solid ' + (followUpStatus === 'Resolved' ? '#DCFCE7' : (followUpStatus === 'Open' ? '#FEF3C7' : '#E2E8F0')),
          color: followUpStatus === 'Resolved' ? '#166534' : (followUpStatus === 'Open' ? '#92400E' : '#475569')
        }}>
          <div><strong>Follow-up Status:</strong> {followUpStatus}</div>
          
          {/* If resolved, show resolution details */}
          {hasResolution && entry.resolution && (
            <div style={{ marginTop: '4px', borderTop: '1px dashed #DCFCE7', paddingTop: '4px' }}>
              <div><strong>Resolution Note:</strong> {entry.resolution.resolution_note}</div>
              <div style={{ fontSize: '10px', color: '#166534', marginTop: '2px' }}>
                Resolved by {entry.resolution.resolver?.name || 'Unknown'} at {new Date(entry.resolution.resolved_at).toLocaleString()}
              </div>
            </div>
          )}
        </div>

        {/* Inline Resolve Follow-up Form */}
        {resolvingEntryId === entry.id && (
          <div style={{ 
            marginTop: '8px', 
            padding: '12px', 
            borderRadius: '6px', 
            background: '#F8FAFC', 
            border: '1px solid #E2E8F0',
            display: 'flex',
            flexDirection: 'column',
            gap: '8px'
          }}>
            <label style={{ fontSize: '12px', fontWeight: 'bold', color: '#475569' }}>Resolution Note</label>
            <textarea 
              className="filter-input"
              style={{ width: '100%', height: '60px', padding: '6px', fontSize: '13px' }}
              value={resolutionText}
              onChange={e => setResolutionText(e.target.value)}
              placeholder="Explain how this issue was resolved..."
              required
            />
            <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
              <Button variant="secondary" size="sm" onClick={() => { setResolvingEntryId(null); setResolutionText(''); }}>Cancel</Button>
              <Button variant="primary" size="sm" onClick={() => handleResolveFollowUp(entry.id)}>Submit Resolution</Button>
            </div>
          </div>
        )}

        <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '8px' }}>
          {entry.status === 'draft' && isCreator && (
            <>
              <Button variant="secondary" size="sm" onClick={() => handleOpEditClick(entry)}>Edit</Button>
              <Button variant="primary" size="sm" onClick={() => handleOpSubmit(entry.id)}>Submit</Button>
            </>
          )}
          {entry.status === 'submitted' && entry.requires_follow_up && !hasResolution && isCreator && resolvingEntryId !== entry.id && (
            <Button variant="primary" size="sm" onClick={() => { setResolvingEntryId(entry.id); setResolutionText(''); }}>Resolve Follow-up</Button>
          )}
        </div>
      </div>
    );
  };

  return (
    <>
      <div className="workspace-container">
        <WorkspaceHeader 
          title="Operations Shift Handover Logbook"
        />
        
        <ModuleTabs tabs={tabs} activeTabId="/logbook" />

        <SplitLayout>
          <QuickFilterPanel>
            <div className="filter-group">
              <label className="filter-label">Category</label>
              <select className="filter-input" value={filterCategory} onChange={e => setFilterCategory(e.target.value)}>
                <option value="All">All Categories</option>
                <option value="Front Desk">Front Desk</option>
                <option value="Housekeeping">Housekeeping</option>
                <option value="Engineering">Engineering</option>
                <option value="F&B">F&B</option>
                {categoriesList.filter(c => !['Front Desk', 'Housekeeping', 'Engineering', 'F&B'].includes(c)).map(c => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </select>
            </div>
            <div className="filter-group" style={{ display: 'flex', alignItems: 'center', gap: '8px', marginTop: '12px' }}>
              <input 
                type="checkbox" 
                id="followupFilter" 
                checked={filterFollowUpOnly} 
                onChange={e => setFilterFollowUpOnly(e.target.checked)} 
              />
              <label htmlFor="followupFilter" style={{ fontSize: '12px', fontWeight: 'bold', color: '#475569', cursor: 'pointer' }}>
                Open Follow-up Only
              </label>
            </div>
          </QuickFilterPanel>

          <MainContent>
            {/* Local workspace sub-tabs */}
            <div style={{ display: 'flex', gap: '16px', borderBottom: '1px solid #E2E8F0', marginBottom: '20px' }}>
              <button 
                onClick={() => setActiveSubTab('handover')} 
                style={{ 
                  padding: '8px 16px', 
                  fontWeight: '600', 
                  fontSize: '14px', 
                  borderBottom: activeSubTab === 'handover' ? '2px solid #3B82F6' : 'none',
                  color: activeSubTab === 'handover' ? '#3B82F6' : '#64748B',
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer'
                }}
              >
                Shift Handovers
              </button>
              <button 
                onClick={() => setActiveSubTab('operational')} 
                style={{ 
                  padding: '8px 16px', 
                  fontWeight: '600', 
                  fontSize: '14px', 
                  borderBottom: activeSubTab === 'operational' ? '2px solid #3B82F6' : 'none',
                  color: activeSubTab === 'operational' ? '#3B82F6' : '#64748B',
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer'
                }}
              >
                My Operational Entries ({myOperationalEntries.length})
              </button>
            </div>

            {activeSubTab === 'handover' && (
              <>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                  <h2 style={{ fontSize: '18px', fontWeight: 'bold', color: '#1E293B' }}>Handover Workspace</h2>
                  {!showCreate && (
                    <Button variant="primary" onClick={() => setShowCreate(true)}>
                      + New Shift Log
                    </Button>
                  )}
                </div>

                {showCreate && (
                  <div style={{ background: '#F8FAFC', border: '1px solid #E2E8F0', padding: '20px', borderRadius: '8px', marginBottom: '24px' }}>
                    <h3 style={{ fontSize: '14px', fontWeight: 'bold', color: '#1E293B', marginBottom: '16px' }}>
                      {editingLog ? 'Edit Shift Log Draft' : 'New Outgoing Shift Log'}
                    </h3>
                    <form onSubmit={handleSubmitForm} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Subject</label>
                          <input 
                            type="text" 
                            className="filter-input" 
                            style={{ width: '100%' }} 
                            value={subject} 
                            onChange={e => setSubject(e.target.value)} 
                            required 
                          />
                        </div>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Category</label>
                          <input 
                            type="text" 
                            className="filter-input" 
                            style={{ width: '100%' }} 
                            value={category} 
                            onChange={e => setCategory(e.target.value)} 
                            placeholder="e.g. Front Desk, Engineering"
                            required 
                          />
                        </div>
                      </div>

                      <div>
                        <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Content</label>
                        <textarea 
                          className="filter-input" 
                          style={{ width: '100%', height: '100px', padding: '8px' }} 
                          value={content} 
                          onChange={e => setContent(e.target.value)} 
                          required 
                        />
                      </div>

                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '16px' }}>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Priority</label>
                          <select className="filter-input" style={{ width: '100%' }} value={priority} onChange={e => setPriority(e.target.value as any)}>
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                          </select>
                        </div>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Shift (Optional)</label>
                          <select className="filter-input" style={{ width: '100%' }} value={shiftId} onChange={e => setShiftId(e.target.value)}>
                            <option value="">None</option>
                            {shifts.map(s => (
                              <option key={s.id} value={s.id}>{s.name} ({s.start_time} - {s.end_time})</option>
                            ))}
                          </select>
                        </div>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Department *</label>
                          <select className="filter-input" style={{ width: '100%' }} value={departmentId} onChange={e => setDepartmentId(e.target.value)} required>
                            <option value="">Select Department...</option>
                            {departments.map(d => (
                              <option key={d.id} value={d.id}>{d.name}</option>
                            ))}
                          </select>
                        </div>
                      </div>

                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px', alignItems: 'center' }}>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Area (Optional)</label>
                          <input 
                            type="text" 
                            className="filter-input" 
                            style={{ width: '100%' }} 
                            value={area} 
                            onChange={e => setArea(e.target.value)} 
                            placeholder="e.g. Lobby, Plant Room"
                          />
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', height: '100%', paddingTop: '20px' }}>
                          <input 
                            type="checkbox" 
                            id="formRequiresFollowUp" 
                            checked={requiresFollowUp} 
                            onChange={e => setRequiresFollowUp(e.target.checked)} 
                          />
                          <label htmlFor="formRequiresFollowUp" style={{ fontSize: '12px', fontWeight: 'bold', cursor: 'pointer' }}>
                            Requires Follow-up
                          </label>
                        </div>
                      </div>

                      <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '8px' }}>
                        <Button variant="secondary" onClick={handleCancel}>Cancel</Button>
                        <Button variant="primary" type="submit">{editingLog ? 'Update Draft' : 'Save Draft'}</Button>
                      </div>
                    </form>
                  </div>
                )}

                {/* Shift Logs Status Grid Layout */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '20px' }}>
                  {/* Draft Column */}
                  <div>
                    <h3 style={{ 
                      fontSize: '12px', 
                      fontWeight: 'bold', 
                      color: '#64748B', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.5px',
                      marginBottom: '16px',
                      display: 'flex',
                      justifyContent: 'space-between'
                    }}>
                      <span>Draft Logs</span>
                      <span style={{ background: '#E2E8F0', padding: '2px 6px', borderRadius: '10px', fontSize: '10px' }}>{drafts.length}</span>
                    </h3>
                    <div>
                      {drafts.length > 0 ? drafts.map(renderCard) : (
                        <div style={{ color: '#94A3B8', fontSize: '13px', textAlign: 'center', padding: '20px', border: '1px dashed #CBD5E1', borderRadius: '8px' }}>
                          No draft logs.
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Submitted Column */}
                  <div>
                    <h3 style={{ 
                      fontSize: '12px', 
                      fontWeight: 'bold', 
                      color: '#D97706', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.5px',
                      marginBottom: '16px',
                      display: 'flex',
                      justifyContent: 'space-between'
                    }}>
                      <span>Submitted for Handover</span>
                      <span style={{ background: '#FEF3C7', padding: '2px 6px', borderRadius: '10px', fontSize: '10px' }}>{submitted.length}</span>
                    </h3>
                    <div>
                      {submitted.length > 0 ? submitted.map(renderCard) : (
                        <div style={{ color: '#94A3B8', fontSize: '13px', textAlign: 'center', padding: '20px', border: '1px dashed #CBD5E1', borderRadius: '8px' }}>
                          No logs submitted for handover.
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Acknowledged Column */}
                  <div>
                    <h3 style={{ 
                      fontSize: '12px', 
                      fontWeight: 'bold', 
                      color: '#059669', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.5px',
                      marginBottom: '16px',
                      display: 'flex',
                      justifyContent: 'space-between'
                    }}>
                      <span>Acknowledged</span>
                      <span style={{ background: '#D1FAE5', padding: '2px 6px', borderRadius: '10px', fontSize: '10px' }}>{acknowledged.length}</span>
                    </h3>
                    <div>
                      {acknowledged.length > 0 ? acknowledged.map(renderCard) : (
                        <div style={{ color: '#94A3B8', fontSize: '13px', textAlign: 'center', padding: '20px', border: '1px dashed #CBD5E1', borderRadius: '8px' }}>
                          No acknowledged logs.
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </>
            )}

            {activeSubTab === 'operational' && (
              <>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                  <h2 style={{ fontSize: '18px', fontWeight: 'bold', color: '#1E293B' }}>My Operational Entries</h2>
                  {!showOpCreate && (
                    <Button variant="primary" onClick={() => setShowOpCreate(true)}>
                      + New Operational Entry
                    </Button>
                  )}
                </div>

                {showOpCreate && (
                  <div style={{ background: '#F8FAFC', border: '1px solid #E2E8F0', padding: '20px', borderRadius: '8px', marginBottom: '24px' }}>
                    <h3 style={{ fontSize: '14px', fontWeight: 'bold', color: '#1E293B', marginBottom: '16px' }}>
                      {editingOpEntry ? 'Edit Operational Entry Draft' : 'New Operational Entry'}
                    </h3>
                    <form onSubmit={handleOpSubmitForm} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Subject</label>
                          <input 
                            type="text" 
                            className="filter-input" 
                            style={{ width: '100%' }} 
                            value={opSubject} 
                            onChange={e => setOpSubject(e.target.value)} 
                            required 
                          />
                        </div>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Category</label>
                          <input 
                            type="text" 
                            className="filter-input" 
                            style={{ width: '100%' }} 
                            value={opCategory} 
                            onChange={e => setOpCategory(e.target.value)} 
                            placeholder="e.g. Front Desk, Engineering"
                            required 
                          />
                        </div>
                      </div>

                      <div>
                        <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Content</label>
                        <textarea 
                          className="filter-input" 
                          style={{ width: '100%', height: '100px', padding: '8px' }} 
                          value={opContent} 
                          onChange={e => setOpContent(e.target.value)} 
                          required 
                        />
                      </div>

                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Priority</label>
                          <select className="filter-input" style={{ width: '100%' }} value={opPriority} onChange={e => setOpPriority(e.target.value as any)}>
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                          </select>
                        </div>
                        <div>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '6px' }}>Department *</label>
                          <select className="filter-input" style={{ width: '100%' }} value={opDepartmentId} onChange={e => setOpDepartmentId(e.target.value)} required>
                            <option value="">Select Department...</option>
                            {departments.map(d => (
                              <option key={d.id} value={d.id}>{d.name}</option>
                            ))}
                          </select>
                        </div>
                      </div>

                      <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <input 
                          type="checkbox" 
                          id="opFormRequiresFollowUp" 
                          checked={opRequiresFollowUp} 
                          onChange={e => setOpRequiresFollowUp(e.target.checked)} 
                        />
                        <label htmlFor="opFormRequiresFollowUp" style={{ fontSize: '12px', fontWeight: 'bold', cursor: 'pointer' }}>
                          Requires Follow-up
                        </label>
                      </div>

                      <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '8px' }}>
                        <Button variant="secondary" onClick={handleOpCancel}>Cancel</Button>
                        <Button variant="primary" type="submit">{editingOpEntry ? 'Update Draft' : 'Save Draft'}</Button>
                      </div>
                    </form>
                  </div>
                )}

                {/* Operational Log Entries Grid */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px' }}>
                  {/* Draft Column */}
                  <div>
                    <h3 style={{ 
                      fontSize: '12px', 
                      fontWeight: 'bold', 
                      color: '#64748B', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.5px',
                      marginBottom: '16px',
                      display: 'flex',
                      justifyContent: 'space-between'
                    }}>
                      <span>Draft Entries</span>
                      <span style={{ background: '#E2E8F0', padding: '2px 6px', borderRadius: '10px', fontSize: '10px' }}>
                        {myOperationalEntries.filter(entry => {
                          if (filterCategory !== 'All' && entry.category !== filterCategory) return false;
                          if (filterFollowUpOnly && !entry.requires_follow_up) return false;
                          return true;
                        }).filter(e => e.status === 'draft').length}
                      </span>
                    </h3>
                    <div>
                      {myOperationalEntries.filter(entry => {
                        if (filterCategory !== 'All' && entry.category !== filterCategory) return false;
                        if (filterFollowUpOnly && !entry.requires_follow_up) return false;
                        return true;
                      }).filter(e => e.status === 'draft').length > 0 ? (
                        myOperationalEntries.filter(entry => {
                          if (filterCategory !== 'All' && entry.category !== filterCategory) return false;
                          if (filterFollowUpOnly && !entry.requires_follow_up) return false;
                          return true;
                        }).filter(e => e.status === 'draft').map(renderOpCard)
                      ) : (
                        <div style={{ color: '#94A3B8', fontSize: '13px', textAlign: 'center', padding: '20px', border: '1px dashed #CBD5E1', borderRadius: '8px' }}>
                          No draft entries.
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Submitted Column */}
                  <div>
                    <h3 style={{ 
                      fontSize: '12px', 
                      fontWeight: 'bold', 
                      color: '#059669', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.5px',
                      marginBottom: '16px',
                      display: 'flex',
                      justifyContent: 'space-between'
                    }}>
                      <span>Submitted Entries</span>
                      <span style={{ background: '#D1FAE5', padding: '2px 6px', borderRadius: '10px', fontSize: '10px' }}>
                        {myOperationalEntries.filter(entry => {
                          if (filterCategory !== 'All' && entry.category !== filterCategory) return false;
                          if (filterFollowUpOnly && !entry.requires_follow_up) return false;
                          return true;
                        }).filter(e => e.status === 'submitted').length}
                      </span>
                    </h3>
                    <div>
                      {myOperationalEntries.filter(entry => {
                        if (filterCategory !== 'All' && entry.category !== filterCategory) return false;
                        if (filterFollowUpOnly && !entry.requires_follow_up) return false;
                        return true;
                      }).filter(e => e.status === 'submitted').length > 0 ? (
                        myOperationalEntries.filter(entry => {
                          if (filterCategory !== 'All' && entry.category !== filterCategory) return false;
                          if (filterFollowUpOnly && !entry.requires_follow_up) return false;
                          return true;
                        }).filter(e => e.status === 'submitted').map(renderOpCard)
                      ) : (
                        <div style={{ color: '#94A3B8', fontSize: '13px', textAlign: 'center', padding: '20px', border: '1px dashed #CBD5E1', borderRadius: '8px' }}>
                          No submitted entries.
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </>
            )}
          </MainContent>
        </SplitLayout>
      </div>
    </>
  );
};

ShiftLogWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default ShiftLogWorkspace;
