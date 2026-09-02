import { useState } from 'react';
import { ChevronDown, LogOut, Menu, Shield, X } from 'lucide-react';
import { useApp } from '../../context/AppContext';
import type { ViewType } from '../../types';
import { UserLogo } from '../brand/UserLogo';

interface UserNavItem {
  label: string;
  view: ViewType;
  activeViews: ViewType[];
}

const userNavItems: UserNavItem[] = [
  { label: 'Home', view: 'user-home', activeViews: ['user-home', 'create-audit'] },
  { label: 'Dashboard', view: 'user-dashboard', activeViews: ['user-dashboard'] },
  { label: 'My Audits', view: 'my-audits', activeViews: ['my-audits', 'audit-detail', 'audit-report'] },
];

export function UserTopNavigation() {
  const { currentUser, currentView, logout, setCurrentView } = useApp();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const initials = currentUser?.name
    .split(/\s+/)
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase() || 'U';

  const navigate = (view: ViewType) => {
    setCurrentView(view);
    setMobileMenuOpen(false);
    setProfileOpen(false);
  };

  return (
    <header className="sticky top-0 z-40 shrink-0 border-b border-[var(--color-border)] bg-[#020C0F]/88 backdrop-blur-xl">
      <div className="mx-auto flex h-16 w-full max-w-[1440px] items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <button type="button" onClick={() => navigate('user-home')} className="group flex shrink-0 items-center gap-3 text-left" aria-label="AuditSEO home">
          <UserLogo size={40} className="h-10 w-10 transition-[filter] duration-200 group-hover:drop-shadow-[0_0_9px_rgba(255,138,0,0.35)] sm:h-12 sm:w-12" />
          <span className="block leading-none"><span className="block text-lg font-black tracking-[-0.035em] text-[#FF8A00] sm:text-xl">AuditSEO</span><span className="mt-1.5 block text-[10px] font-extrabold uppercase tracking-[0.18em] text-[#F8FAFC] sm:text-[11px]">AI PLATFORM</span></span>
        </button>

        <nav className="hidden h-full items-center gap-2 lg:flex xl:gap-3" aria-label="User navigation">
          {userNavItems.map((item) => {
            const active = item.activeViews.includes(currentView);
            return (
              <button key={item.view} type="button" onClick={() => navigate(item.view)} className={`relative flex h-full items-center px-4 transition-all xl:px-5 ${active ? 'text-[17px] font-black text-[#FF8A00]' : 'text-base font-semibold text-[#F8FAFC] hover:text-white'}`}>
                {item.label}
                <span className={`absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-[#FF8A00] shadow-[0_0_10px_rgba(255,138,0,0.45)] transition-transform ${active ? 'scale-x-100' : 'scale-x-0'}`} />
              </button>
            );
          })}
        </nav>

        <div className="flex items-center gap-2">
          <div className="relative hidden sm:block">
            <button type="button" onClick={() => setProfileOpen((open) => !open)} className="flex items-center gap-2 rounded-full border border-[var(--color-border)] bg-[var(--color-surface)] p-1 pr-2.5 text-left transition-colors hover:border-[var(--color-primary)]/40" aria-expanded={profileOpen} aria-label="Open user menu">
              <span className="flex h-7 w-7 items-center justify-center rounded-full bg-[var(--color-primary)] text-[10px] font-black text-[var(--color-on-primary)]">{initials}</span>
              <ChevronDown className="h-3.5 w-3.5 text-[var(--color-muted)]" />
            </button>
            {profileOpen ? (
              <div className="absolute right-0 mt-2 w-64 rounded-2xl border border-[var(--color-border)] bg-[#071416] p-2 shadow-2xl">
                <div className="border-b border-[var(--color-border)] px-3 py-2.5">
                  <p className="truncate text-xs font-bold text-white">{currentUser?.name || 'User'}</p>
                  <p className="mt-1 truncate text-[10px] text-[var(--color-muted)]">{currentUser?.email}</p>
                </div>
                <button type="button" onClick={() => void logout()} className="mt-1 flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-xs font-bold text-[var(--color-danger-text)] transition-colors hover:bg-[var(--color-danger-bg)]"><LogOut className="h-4 w-4" />Logout</button>
              </div>
            ) : null}
          </div>

          <button type="button" onClick={() => setMobileMenuOpen((open) => !open)} className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-muted)] lg:hidden" aria-label={mobileMenuOpen ? 'Close navigation' : 'Open navigation'} aria-expanded={mobileMenuOpen}>
            {mobileMenuOpen ? <X className="h-4 w-4" /> : <Menu className="h-4 w-4" />}
          </button>
        </div>
      </div>

      {mobileMenuOpen ? (
        <div className="border-t border-[var(--color-border)] bg-[#020C0F]/96 px-4 py-3 shadow-2xl lg:hidden">
          <nav className="mx-auto grid max-w-[1440px] gap-1" aria-label="Mobile user navigation">
            {userNavItems.map((item) => {
              const active = item.activeViews.includes(currentView);
              return <button key={item.view} type="button" onClick={() => navigate(item.view)} className={`flex items-center justify-between rounded-xl border px-3.5 py-2.5 text-left font-bold ${active ? 'border-[var(--color-primary)]/30 bg-[var(--color-primary)]/10 text-[13px] text-[var(--color-primary)]' : 'border-transparent text-xs text-white/90 hover:bg-[var(--color-surface)] hover:text-white'}`}><span>{item.label}</span>{active ? <span className="h-1.5 w-1.5 rounded-full bg-[var(--color-primary)]" /> : null}</button>;
            })}
            <div className="mt-2 flex items-center justify-between border-t border-[var(--color-border)] pt-3">
              <div className="flex min-w-0 items-center gap-2"><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--color-primary)] text-[10px] font-black text-[var(--color-on-primary)]">{initials}</span><span className="min-w-0"><span className="block truncate text-xs font-bold text-white">{currentUser?.name || 'User'}</span><span className="block truncate text-[9px] text-[var(--color-muted)]">{currentUser?.email}</span></span></div>
              <button type="button" onClick={() => void logout()} className="ml-3 inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold text-[var(--color-danger-text)] hover:bg-[var(--color-danger-bg)]"><LogOut className="h-3.5 w-3.5" />Logout</button>
            </div>
            <p className="mt-2 flex items-center gap-1.5 px-3 text-[9px] font-semibold text-[var(--color-muted)]"><Shield className="h-3 w-3 text-[var(--color-primary)]" />Authenticated SEO workspace</p>
          </nav>
        </div>
      ) : null}
    </header>
  );
}
