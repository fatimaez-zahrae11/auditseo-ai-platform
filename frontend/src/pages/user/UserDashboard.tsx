import React, { useEffect, useState } from 'react';
import { ArrowRight, CheckCircle2, Clock3, Gauge, History, Loader2, RefreshCw, ShieldAlert } from 'lucide-react';
import { AuditScoreTrendChart } from '../../components/charts/AuditScoreTrendChart';
import { StatusDistributionChart } from '../../components/charts/StatusDistributionChart';
import { EmptyState } from '../../components/ui/EmptyState';
import { RequestErrorState } from '../../components/ui/RequestErrorState';
import { ScoreCircle } from '../../components/ui/ScoreCircle';
import { StatusBadge } from '../../components/ui/StatusBadge';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';
import { auditService } from '../../services/auditService';
import type { SeoAudit, UserDashboardData } from '../../types';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

export const UserDashboard: React.FC = () => {
  const { currentUser, selectAudit, setCurrentView } = useApp();
  const [dashboard, setDashboard] = useState<UserDashboardData | null>(null);
  const [recentAudits, setRecentAudits] = useState<SeoAudit[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState('');
  const [recentAuditsError, setRecentAuditsError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => {
    let active = true;
    setIsLoading(true);
    setErrorMessage('');
    setRecentAuditsError('');

    const loadDashboard = async () => {
      const [dashboardResult, auditsResult] = await Promise.allSettled([
        auditService.getDashboard(),
        auditService.listAudits(1),
      ]);

      if (!active) return;

      const handleForbidden = (error: unknown) => {
        if (!(error instanceof ApiError) || error.status !== 403) return false;
        if (error.message === 'Account disabled') setCurrentView('account-disabled');
        else if (error.message.toLowerCase().includes('verif')) setCurrentView('email-verification');
        else setCurrentView('error-403');
        return true;
      };

      if (dashboardResult.status === 'fulfilled') {
        setDashboard(dashboardResult.value);
      } else {
        const requestError = dashboardResult.reason as unknown;
        if (handleForbidden(requestError)) return;
        setErrorMessage(requestError instanceof ApiError && requestError.status === 429
          ? 'Dashboard is temporarily rate limited. Please wait a moment and refresh.'
          : requestError instanceof ApiError && requestError.status === 0
            ? 'The dashboard service is unreachable. Check your connection and try again.'
            : 'Your dashboard could not be loaded. Please try again.');
      }

      if (auditsResult.status === 'fulfilled') {
        setRecentAudits(auditsResult.value.audits);
      } else {
        const requestError = auditsResult.reason as unknown;
        if (handleForbidden(requestError)) return;
        setRecentAuditsError(requestError instanceof ApiError && requestError.status === 429
          ? 'Recent audits are temporarily rate limited. Refresh in a moment.'
          : 'Recent audits could not be loaded.');
      }
    };

    void loadDashboard()
      .catch((error: unknown) => {
        if (!active) return;
        setErrorMessage(getPublicApiErrorMessage(error, { fallback: 'Your dashboard could not be loaded. Please try again.' }));
      })
      .finally(() => {
        if (active) setIsLoading(false);
      });

    return () => { active = false; };
  }, [retryKey, setCurrentView]);

  if (isLoading) {
    return <div className="flex min-h-[50vh] items-center justify-center"><div className="flex items-center gap-3 text-sm font-bold text-[var(--color-muted)]"><Loader2 className="h-5 w-5 animate-spin text-[var(--color-primary)]" />Loading your dashboard...</div></div>;
  }

  if (errorMessage || !dashboard) {
    return <RequestErrorState title="Dashboard unavailable" message={errorMessage || 'No dashboard data was returned.'} onRetry={() => setRetryKey((key) => key + 1)} retryLabel="Retry" />;
  }

  const latestCompleted = dashboard.latestCompletedAudit
    || recentAudits.find((audit) => audit.status === 'completed')
    || null;
  const processingCount = dashboard.pendingAudits + dashboard.runningAudits;
  const metrics = [
    { label: 'Total audits', value: dashboard.totalAudits, icon: History, tone: 'text-[var(--color-primary)]' },
    { label: 'Completed', value: dashboard.completedAudits, icon: CheckCircle2, tone: 'text-[var(--color-completed-bg)]' },
    { label: 'Processing', value: processingCount, icon: Clock3, tone: 'text-[var(--color-running-bg)]' },
    { label: 'Failed', value: dashboard.failedAudits, icon: ShieldAlert, tone: 'text-[var(--color-danger-text)]' },
    { label: 'Average score', value: dashboard.completedAudits ? `${Math.round(dashboard.averageGlobalScore)}/100` : '—', icon: Gauge, tone: 'text-[var(--color-primary)]' },
  ];

  return (
    <div id="user-dashboard-view" className="mx-auto max-w-7xl space-y-4 pb-4">
      <header className="flex flex-col justify-between gap-4 border-b border-[var(--color-border)] pb-5 sm:flex-row sm:items-end">
        <div><p className="user-kicker">Workspace overview</p><h1 className="mt-1.5 text-2xl font-black tracking-[-0.035em] text-[var(--color-text)] sm:text-3xl">Welcome back, {currentUser?.name || 'there'}</h1><p className="mt-1.5 text-sm text-[var(--color-muted)]">Your latest SEO audit performance and activity at a glance.</p></div>
        <div className="flex gap-2"><button onClick={() => setRetryKey((key) => key + 1)} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-3.5 text-xs font-bold text-[var(--color-muted)] transition-colors hover:border-[var(--color-primary)]/40 hover:text-[var(--color-text)]"><RefreshCw className="h-4 w-4" />Refresh</button><button onClick={() => setCurrentView('user-home')} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-primary)] px-4 text-xs font-black text-[var(--color-on-primary)] shadow-[0_10px_24px_rgba(255,138,0,0.18)] transition-colors hover:bg-[var(--color-primary-hover)]">Start new audit <ArrowRight className="h-4 w-4" /></button></div>
      </header>

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        {metrics.map(({ label, value, icon: Icon, tone }, index) => <article key={label} className={`user-panel rounded-2xl p-4 ${index === metrics.length - 1 ? 'border-[var(--color-primary)]/25' : ''}`}><div className="flex items-center justify-between"><p className="text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]">{label}</p><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--color-surface-muted)]"><Icon className={`h-4 w-4 ${tone}`} /></span></div><p className="mt-2 text-2xl font-black text-[var(--color-text)]">{value}</p></article>)}
      </section>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(300px,0.8fr)]">
        <section className="user-panel rounded-2xl p-4 sm:p-5"><AuditScoreTrendChart audits={recentAudits} /></section>
        <section className="user-panel rounded-2xl p-4 sm:p-5"><StatusDistributionChart statusCounts={{ completed: dashboard.completedAudits, pending: dashboard.pendingAudits, running: dashboard.runningAudits, failed: dashboard.failedAudits }} /></section>
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(260px,0.6fr)_minmax(0,1.4fr)]">
        <section className="user-panel rounded-2xl p-5">
          <div className="flex items-center justify-between"><div><p className="text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]">Latest completed audit</p><h2 className="mt-1 text-lg font-black text-[var(--color-text)]">Score overview</h2></div>{latestCompleted ? <StatusBadge status="completed" size="sm" /> : null}</div>
          {latestCompleted ? <div className="mt-5"><div className="flex justify-center"><ScoreCircle score={latestCompleted.globalScore} size="lg" showLabel label="Global SEO score" /></div><div className="mt-5 border-t border-[var(--color-border)] pt-4"><p className="truncate text-sm font-bold text-[var(--color-text)]">{latestCompleted.domain}</p><p className="mt-1 truncate font-mono text-[10px] text-[var(--color-muted)]">{latestCompleted.finalUrl || latestCompleted.requestedUrl}</p><button onClick={() => selectAudit(latestCompleted.id)} className="mt-4 inline-flex items-center gap-1.5 text-xs font-black text-[var(--color-primary)]">Open report <ArrowRight className="h-3.5 w-3.5" /></button></div></div> : <div className="mt-5"><EmptyState title="No completed audit" description="Complete an audit to see its latest score." compact /></div>}
        </section>

        <section className="user-panel overflow-hidden rounded-2xl">
          <div className="flex items-center justify-between border-b border-[var(--color-border)] px-5 py-4"><div><h2 className="text-base font-black text-[var(--color-text)]">Recent audits</h2><p className="mt-0.5 text-xs text-[var(--color-muted)]">Latest records returned by your audit history.</p></div><button onClick={() => setCurrentView('my-audits')} className="rounded-lg px-2.5 py-1.5 text-xs font-black text-[var(--color-primary)] hover:bg-[var(--color-surface-muted)]">View all</button></div>
          {recentAuditsError ? <div className="border-b border-[var(--color-border)] p-4 text-center"><p className="text-xs font-semibold text-[var(--color-muted)]">{recentAuditsError}</p><button onClick={() => setRetryKey((key) => key + 1)} className="mt-2 text-xs font-black text-[var(--color-primary)]">Retry</button></div> : null}
          {recentAudits.length === 0 ? <div className="p-5"><EmptyState title="No audits yet" description="Analyze your first website to populate the dashboard." compact /></div> : <div className="divide-y divide-[var(--color-border)]">{recentAudits.slice(0, 6).map((audit) => <button key={audit.id} onClick={() => selectAudit(audit.id)} className="grid w-full grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-3 px-5 py-3.5 text-left transition-colors hover:bg-[var(--color-surface-muted)]"><div className="min-w-0"><p className="truncate text-xs font-bold text-[var(--color-text)]">{audit.domain}</p><p className="mt-0.5 truncate font-mono text-[10px] text-[var(--color-muted)]">{audit.requestedUrl}</p></div><StatusBadge status={audit.status} size="sm" /><span className="w-12 text-right text-xs font-black text-[var(--color-text)]">{audit.status === 'completed' ? audit.globalScore : '—'}</span></button>)}</div>}
        </section>
      </div>
    </div>
  );
};
