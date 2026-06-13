import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import Icon from '../primitives/Icon';

export default function TopNav() {
  const { url } = usePage();

  const navItems = [
    { href: '/', name: 'home', label: 'Home', color: '#94A3B8' },
    { href: '/frontdesk', name: 'frontdesk', label: 'Front Desk', color: '#3B82F6' },
    { href: '/housekeeping', name: 'housekeeping', label: 'Housekeeping', color: '#10B981' },
    { href: '/engineering', name: 'engineering', label: 'Engineering', color: '#F97316' },
    { href: '/inventory', name: 'inventory', label: 'Inventory', color: '#6366F1' },
    { href: '/procurement', name: 'procurement', label: 'Procurement', color: '#F59E0B' },
    { href: '/hris', name: 'hris', label: 'HRIS', color: '#8B5CF6' },
    { href: '/finance', name: 'finance', label: 'Finance', color: '#22C55E' },
    { href: '/reports', name: 'reports', label: 'Reports', color: '#06B6D4' },
    { href: '/ai', name: 'ai', label: 'AI Assistant', color: '#A855F7' },
  ];

  return (
    <nav className="topbar-nav">
      {navItems.map(item => (
        <Link 
          key={item.href}
          href={item.href} 
          className={`nav-item ${url.startsWith(item.href) && item.href !== '/' ? 'active' : ''}`}
        >
          <Icon name={item.name as any} style={{ color: item.color, marginRight: '6px' }} />
          {item.label}
        </Link>
      ))}
    </nav>
  );
}
