import React from 'react';
import '../../../../css/ivorq-prototype.css';

import { financeData } from '../../../data/ivorq/finance';
import IvorqLayout from '../../../Layouts/IvorqLayout';

import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';

import BoardHeader from '../../../Components/Ivorq/housekeeping/BoardHeader';
import WorkBoard from '../../../Components/Ivorq/housekeeping/WorkBoard';
import BoardColumn from '../../../Components/Ivorq/housekeeping/BoardColumn';
import WorkCard from '../../../Components/Ivorq/housekeeping/WorkCard';

import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';

import ProgressBarCard from '../../../Components/Ivorq/finance/ProgressBarCard';

interface FinanceWorkspaceProps {
  activeTab?: string;
  capabilities?: {
    can_view_fx_adjustments?: boolean;
  };
}

const FinanceWorkspace = ({ activeTab = 'revenue_cash', capabilities = {} }: FinanceWorkspaceProps) => {
  const tabs = [
    { href: '/finance/revenue-cash', label: 'Revenue & Cash', badge: 3 },
    { href: '/finance/accounts-payable', label: 'Accounts Payable', badge: 12 },
    { href: '/finance/accounts-receivable', label: 'Accounts Receivable', badge: 8 },
    { href: '/finance/budget-watch', label: 'Budget Watch' },
    ...(capabilities.can_view_fx_adjustments
      ? [{ href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' }]
      : []),
  ];

  return (
    <>
      <div className="workspace">
        <WorkspaceHeader title="Operational Finance">
          <Button variant="secondary">
            <Icon name="print" /> Print
          </Button>
          <Button variant="secondary">
            <Icon name="export-xlsx" /> Export XLSX
          </Button>
        </WorkspaceHeader>

        <ModuleTabs tabs={tabs} />

        <SplitLayout>
          <QuickFilterPanel>
            <div className="filter-group">
              <label className="filter-label">Business Date</label>
              <input type="date" className="filter-input" defaultValue="2026-06-14" />
            </div>
            <div className="filter-group">
              <label className="filter-label">Cost Center</label>
              <select className="filter-input">
                <option>All Property</option>
                <option>Rooms</option>
                <option>F&B</option>
              </select>
            </div>
          </QuickFilterPanel>

          <MainContent>
            <OperationalSnapshot>
              <SnapshotCard 
                value={financeData.snapshots.revenueToday.value} 
                label="Revenue Today" 
                statusColor="ready-green" 
                trend={
                  <span style={{ color: `var(--${financeData.snapshots.revenueToday.trend.color})`, fontSize: '11px', fontWeight: 600 }}>
                    {financeData.snapshots.revenueToday.trend.label}
                  </span>
                }
              />
              <SnapshotCard 
                value={financeData.snapshots.cashPosition.value} 
                label="Cash Position" 
                statusColor="inspection-blue" 
                trend={
                  <span style={{ color: `var(--${financeData.snapshots.cashPosition.trend.color})`, fontSize: '11px', fontWeight: 600 }}>
                    {financeData.snapshots.cashPosition.trend.label}
                  </span>
                }
              />
              <SnapshotCard 
                value={financeData.snapshots.apPending.value} 
                label="AP Pending Approval" 
                statusColor="warning-amber" 
              />
              <SnapshotCard 
                value={financeData.snapshots.arOverdue.value} 
                label="AR Overdue" 
                statusColor="critical-red" 
              />
            </OperationalSnapshot>

            <AttentionArea title="Budget Watch & Cost Control" badgeType="warning" badgeText="3 Indicators" areaType="warning">
              {financeData.budgetWatch.map(item => (
                <ProgressBarCard
                  key={item.id}
                  title={item.title}
                  percent={item.percent}
                  barColor={item.color}
                  trend={
                    item.trend && (
                      <span style={{ fontSize: '11px' }}>
                        {item.trend.label}
                      </span>
                    )
                  }
                />
              ))}
            </AttentionArea>

            <BoardHeader title="AP & AR Workflow" />

            <WorkBoard>
              <BoardColumn title="AP Pending Approval" count={12}>
                {financeData.apPendingApproval.map(card => (
                  <WorkCard
                    key={card.id}
                    borderColor={card.borderColor}
                    meta={
                      <>
                        <span>{card.metaLeft}</span>
                        <span style={{ fontWeight: 600 }}>{card.metaRight}</span>
                      </>
                    }
                    title={card.title}
                    detail={
                      card.detailColor ? (
                        <span style={{ color: `var(--${card.detailColor})` }}>{card.detail}</span>
                      ) : (
                        card.detail
                      )
                    }
                    actions={
                      card.actions.map((act, i) => (
                        <Button key={i} variant={act.isPrimary ? 'primary' : 'secondary'} size="sm">
                          {act.label}
                        </Button>
                      ))
                    }
                  />
                ))}
              </BoardColumn>
              <BoardColumn title="AR Follow-Up (Overdue)" count={4}>
                {financeData.arFollowUp.map(card => (
                  <WorkCard
                    key={card.id}
                    borderColor={card.borderColor}
                    meta={
                      <>
                        <span>{card.metaLeft}</span>
                        <span style={{ fontWeight: 600 }}>{card.metaRight}</span>
                      </>
                    }
                    title={card.title}
                    detail={card.detail}
                    actions={
                      card.actions.map((act, i) => (
                        <Button key={i} variant={act.isPrimary ? 'primary' : 'secondary'} size="sm">
                          {act.label}
                        </Button>
                      ))
                    }
                  />
                ))}
              </BoardColumn>
            </WorkBoard>
          </MainContent>
        </SplitLayout>
      </div>
    </>
  );
};

FinanceWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default FinanceWorkspace;
