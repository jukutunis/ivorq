import React from 'react';
import TopNav from '../navigation/TopNav';
import GlobalSearch from '../navigation/GlobalSearch';
import NotificationCenter from './NotificationCenter';

export default function AppTopbar() {
  return (
    <header className="topbar">
      <div className="topbar-brand">IVORQ</div>
      <TopNav />
      <GlobalSearch />
      
      <div className="topbar-right">
        <NotificationCenter />
        <span>The Grand Resort &amp; Spa</span>
        <div className="topbar-avatar">MS</div>
      </div>
    </header>
  );
}
