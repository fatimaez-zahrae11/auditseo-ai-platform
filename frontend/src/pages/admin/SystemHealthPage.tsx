import React, { useCallback, useEffect, useState } from 'react';
import { Activity, Database, HardDrive, RefreshCw, Server, Workflow } from 'lucide-react';
import { LoadingState } from '../../components/ui/LoadingState';
import { adminMonitoringService } from '../../services/adminMonitoringService';
import type { AdminSystemHealthDetailed } from '../../types';
import { adminReadErrorMessage } from '../../utils/adminApiErrors';

const statusTone = (status: string) => {
  const normalized = status.toLowerCase();
  if (['ok', 'healthy', 'connected', 'not_required'].includes(normalized)) return 'border-[var(--color-primary)]/40 bg-[var(--color-completed-bg)] text-[var(--color-completed-text)]';
  if (['warning', 'degraded', 'reconnecting'].includes(normalized)) return 'border-[var(--color-warning-border)] bg-[var(--color-warning-bg)] text-[var(--color-warning-text)]';
  return 'border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] text-[var(--color-danger-text)]';
};
const readable = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const count = (value: number | null, suffix = '') => value === null ? 'Unavailable' : `${value.toLocaleString()}${suffix}`;

export const SystemHealthPage: React.FC = () => {
  const [health, setHealth] = useState<AdminSystemHealthDetailed | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadHealth = useCallback(async () => {
    setLoading(true); setError('');
    try { setHealth(await adminMonitoringService.systemHealth()); }
    catch (requestError) { setError(adminReadErrorMessage(requestError, 'detailed system health')); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { void loadHealth(); }, [loadHealth]);
  if (loading) return <LoadingState label="Loading detailed system health..." />;

  return (
    <div id="system-health-detailed-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div><h1 className="text-2xl font-black tracking-tight">System Health</h1><p className="mt-1 text-xs text-[var(--color-muted)]">Read-only operational checks returned by the protected Laravel health endpoint.</p></div><button onClick={() => void loadHealth()} className="inline-flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2 text-xs font-bold"><RefreshCw className="h-4 w-4" />Diagnostic pulse</button></div>
      {error || !health ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">Detailed system health unavailable</p><p className="mt-1">{error || 'The API returned no health data.'}</p></div> : null}

      {health ? <>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md"><div className="flex items-center justify-between text-[10px] font-bold uppercase text-[var(--color-muted)]"><span>Application</span><Server className="h-4 w-4 text-[var(--color-primary)]" /></div><p className="mt-2 text-xl font-black">{readable(health.appEnvironment)}</p><p className={`mt-3 inline-flex rounded-full border px-2.5 py-1 text-[10px] font-bold ${health.debugEnabled ? statusTone('warning') : statusTone('ok')}`}>Debug {health.debugEnabled ? 'enabled' : 'disabled'}</p></div>
          {[{ label: 'Database', status: health.databaseStatus, icon: Database }, { label: 'Redis', status: health.redisStatus, icon: HardDrive }, { label: 'Cache', status: health.cacheStatus, icon: Activity }].map(({ label, status, icon: Icon }) => <div key={label} className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md"><div className="flex items-center justify-between text-[10px] font-bold uppercase text-[var(--color-muted)]"><span>{label}</span><Icon className="h-4 w-4 text-[var(--color-primary)]" /></div><p className={`mt-4 inline-flex rounded-full border px-3 py-1.5 text-xs font-black ${statusTone(status)}`}>{readable(status)}</p></div>)}
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          <div className="space-y-4 rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-xl"><div className="flex items-center gap-2 border-b border-[var(--color-border)] pb-3"><Workflow className="h-4 w-4 text-[var(--color-primary)]" /><h2 className="text-sm font-bold uppercase">Runtime configuration</h2></div><div className="space-y-3 text-xs">{[{ label: 'Queue connection', value: health.queueConnection }, { label: 'Cache driver', value: health.cacheDriver }].map((item) => <div key={item.label} className="flex items-center justify-between rounded-2xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-4"><span className="text-[var(--color-muted)]">{item.label}</span><span className="font-mono font-bold">{readable(item.value)}</span></div>)}</div></div>
          <div className="space-y-4 rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-xl"><div className="flex items-center gap-2 border-b border-[var(--color-border)] pb-3"><Activity className="h-4 w-4 text-[var(--color-primary)]" /><h2 className="text-sm font-bold uppercase">Operational risk counters</h2></div><div className="grid gap-3 sm:grid-cols-2">{[{ label: 'Stale pending audits', value: count(health.stalePendingAudits) }, { label: 'Stale running audits', value: count(health.staleRunningAudits) }, { label: 'Recent failed audits', value: count(health.recentFailedAudits) }, { label: 'Recent failed jobs', value: count(health.recentFailedJobs) }, { label: 'Access logs / 24h', value: count(health.accessLogsLast24h), wide: true }].map((item) => <div key={item.label} className={`rounded-2xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-4 ${item.wide ? 'sm:col-span-2' : ''}`}><p className="text-[10px] font-bold uppercase text-[var(--color-muted)]">{item.label}</p><p className="mt-1 text-xl font-black">{item.value}</p></div>)}</div></div>
        </div>
        <p className="text-right text-[10px] text-[var(--color-muted)]">{health.generatedAt ? `Generated ${new Date(health.generatedAt).toLocaleString()}` : 'Generation time unavailable'}</p>
      </> : null}
    </div>
  );
};
