import React, { useState } from 'react';
import { ChevronDown, LogOut, Menu, Shield } from 'lucide-react';
import { useApp } from '../../context/AppContext';
import type { ViewType } from '../../types';

interface TopbarProps { onOpenMobileMenu: () => void }

const pageLabels: Partial<Record<ViewType, string>> = {
  'user-home': 'Home / Analyze', 'user-dashboard': 'Dashboard', 'create-audit': 'Home / Analyze', 'my-audits': 'My Audits',
  'audit-detail': 'SEO Audit Report', 'audit-report': 'PDF Report', 'admin-dashboard': 'Dashboard',
  'users-management': 'Users Management', 'user-activity': 'User Activity', 'admin-audits': 'Audit Supervision',
  'admin-recommendations': 'AI Recommendations', 'active-users-analytics': 'Active Users',
  'heavy-users-analytics': 'Heavy Users', 'system-health': 'System Health', 'system-logs': 'System Logs',
  'admin-action-logs': 'Action Logs',
  'ip-intelligence': 'IP Intelligence',
};

export const Topbar: React.FC<TopbarProps> = ({ onOpenMobileMenu }) => {
  const { currentUser, currentView, logout } = useApp();
  const [profileOpen, setProfileOpen] = useState(false);
  const isAdmin = currentUser?.role === 'admin';
  const isAdminDashboard = isAdmin && currentView === 'admin-dashboard';
  const initials = currentUser?.name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase() || 'U';

  return (
    <header id="app-top-header" className={`sticky top-0 z-30 flex items-center justify-between px-4 text-[var(--color-text)] sm:px-6 ${isAdminDashboard ? 'h-14 border-b border-transparent bg-transparent shadow-none' : `border-b border-[var(--color-border)] bg-[var(--color-topbar)] shadow-sm backdrop-blur-md ${isAdmin ? 'h-16' : 'h-14'}`}`}>
      <div className="flex min-w-0 items-center gap-3">
        <button type="button" onClick={onOpenMobileMenu} className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] text-[var(--color-muted)] md:hidden" aria-label="Open navigation"><Menu className="h-4 w-4" /></button>
        {!isAdminDashboard ? <div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--color-muted)]">{isAdmin ? 'Administration' : 'Audit Workspace'}</p><h1 className="truncate text-sm font-black sm:text-base">{pageLabels[currentView] || (isAdmin ? 'Administration' : 'Workspace')}</h1></div> : null}
      </div>

      <div className="flex items-center gap-2 sm:gap-3">
        <div className="relative">
          <button onClick={() => setProfileOpen((open) => !open)} className={`flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-left hover:border-[var(--color-primary)] ${isAdmin ? 'p-1.5' : 'p-1 pr-2'} ${isAdminDashboard ? 'pr-1.5' : 'sm:pr-2.5'}`} aria-expanded={profileOpen}>
            <span className={`flex items-center justify-center rounded-lg bg-[var(--color-primary)] text-[11px] font-black text-[var(--color-on-primary)] ${isAdmin ? 'h-8 w-8' : 'h-7 w-7'}`}>{initials}</span>
            <span className={isAdminDashboard ? 'hidden' : 'hidden max-w-40 sm:block'}><span className="block truncate text-xs font-bold">{currentUser?.name}</span><span className="block truncate text-[10px] text-[var(--color-muted)]">{currentUser?.email}</span></span>
            <ChevronDown className={`h-3.5 w-3.5 text-[var(--color-muted)] ${isAdminDashboard ? 'hidden' : ''}`} />
          </button>
          {profileOpen ? <div className="absolute right-0 mt-2 w-64 rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-2 shadow-2xl"><div className="border-b border-[var(--color-border)] px-3 py-2"><div className="flex items-center gap-2 text-xs font-bold"><Shield className="h-3.5 w-3.5 text-[var(--color-primary)]" />{isAdmin ? 'Administrator' : 'Regular User'}</div><p className="mt-1 truncate text-[11px] text-[var(--color-muted)]">{currentUser?.email}</p></div><button onClick={() => void logout()} className="mt-1 flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-xs font-bold text-[var(--color-danger-text)] hover:bg-[var(--color-danger-bg)]"><LogOut className="h-4 w-4" />Logout</button></div> : null}
        </div>
      </div>
    </header>
  );
};
