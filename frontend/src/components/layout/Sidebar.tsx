import React from 'react';
import { Activity, Bot, FileText, Home, LayoutDashboard, ListOrdered, Server, ShieldAlert, SlidersHorizontal, Terminal, UserCheck, Users, Zap } from 'lucide-react';
import { useApp } from '../../context/AppContext';
import type { ViewType } from '../../types';
import { UserLogo } from '../brand/UserLogo';
import { AppLogo } from '../ui/AppLogo';

interface SidebarProps { onNavigate?: () => void }
interface NavItem { label: string; view: ViewType; icon: React.ElementType }

const userNav: NavItem[] = [
  { label: 'Home / Analyze', view: 'user-home', icon: Home },
  { label: 'Dashboard', view: 'user-dashboard', icon: LayoutDashboard },
  { label: 'My Audits', view: 'my-audits', icon: ListOrdered },
];
const adminNav: NavItem[] = [
  { label: 'Dashboard', view: 'admin-dashboard', icon: LayoutDashboard },
  { label: 'Users', view: 'users-management', icon: Users },
  { label: 'User Activity', view: 'user-activity', icon: Activity },
  { label: 'Audits', view: 'admin-audits', icon: SlidersHorizontal },
  { label: 'AI Recommendations', view: 'admin-recommendations', icon: Bot },
  { label: 'Active Users', view: 'active-users-analytics', icon: UserCheck },
  { label: 'Heavy Users', view: 'heavy-users-analytics', icon: Zap },
  { label: 'IP Intelligence', view: 'ip-intelligence', icon: ShieldAlert },
  { label: 'System Health', view: 'system-health', icon: Server },
  { label: 'System Logs', view: 'system-logs', icon: Terminal },
  { label: 'Action Logs', view: 'admin-action-logs', icon: FileText },
];

export const Sidebar: React.FC<SidebarProps> = ({ onNavigate }) => {
  const { currentUser, currentView, setCurrentView } = useApp();
  const isAdmin = currentUser?.role === 'admin';
  const navItems = isAdmin ? adminNav : userNav;
  return (
    <aside id="app-left-sidebar" className={`flex h-screen min-h-0 shrink-0 flex-col overflow-hidden border-r border-[var(--color-border)] bg-[var(--color-sidebar)] text-[var(--color-muted)] ${isAdmin ? 'w-64 shadow-xl' : 'w-60 shadow-[12px_0_40px_rgba(0,0,0,0.2)]'}`}>
      <div className={`flex shrink-0 items-center gap-3 border-b border-[var(--color-border)] px-5 ${isAdmin ? 'h-16' : 'h-14'}`}>{isAdmin ? <UserLogo size={40} className="drop-shadow-[0_0_8px_rgba(255,138,0,0.2)]" /> : <AppLogo size={34} />}<div className="min-w-0"><p className="truncate text-sm font-extrabold tracking-tight text-[var(--color-text)]">AuditSEO AI Platform</p><p className="text-[10px] font-medium">{isAdmin ? 'Admin Control' : 'SEO Workspace'}</p></div></div>
      <nav className={`min-h-0 flex-1 space-y-1 overflow-y-auto px-3 ${isAdmin ? 'py-5' : 'py-4'}`} aria-label={isAdmin ? 'Admin navigation' : 'User navigation'}><p className="px-3 pb-2 text-[10px] font-bold uppercase tracking-[0.16em] text-[var(--color-muted)]/70">{isAdmin ? 'Administration' : 'Workspace'}</p>{navItems.map((item) => { const Icon = item.icon; const active = currentView === item.view || (!isAdmin && item.view === 'user-home' && currentView === 'create-audit'); const activeClass = isAdmin ? 'bg-[var(--color-primary)] font-bold text-[var(--color-on-primary)] shadow-md' : 'border border-[var(--color-primary)]/25 bg-[var(--color-primary)]/10 font-bold text-[var(--color-primary)] shadow-[0_8px_24px_rgba(255,138,0,0.08)]'; return <button key={item.view} onClick={() => { setCurrentView(item.view); onNavigate?.(); }} className={`flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-xs transition-colors ${active ? activeClass : 'border border-transparent font-medium text-[var(--color-muted)] hover:bg-[var(--color-surface-muted)] hover:text-[var(--color-text)]'}`}><Icon className="h-4 w-4" /><span>{item.label}</span></button>; })}</nav>
      <div className="shrink-0 border-t border-[var(--color-border)] p-4"><p className="truncate text-xs font-bold text-[var(--color-text)]">{currentUser?.name}</p><p className="mt-0.5 truncate text-[10px] text-[var(--color-muted)]">{currentUser?.email}</p></div>
    </aside>
  );
};
