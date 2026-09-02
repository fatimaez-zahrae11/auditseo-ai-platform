import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  Activity,
  AlertCircle,
  ArrowUpRight,
  Bot,
  Database,
  FileCheck2,
  HardDrive,
  RefreshCw,
  Server,
  ShieldCheck,
  Users,
  Workflow,
} from 'lucide-react';
import { PlatformActivityChart } from '../../components/charts/PlatformActivityChart';
import { StatusDistributionChart } from '../../components/charts/StatusDistributionChart';
import { WebTrafficOverviewChart } from '../../components/charts/WebTrafficOverviewChart';
import { LoadingState } from '../../components/ui/LoadingState';
import { useApp } from '../../context/AppContext';
import { adminAnalyticsService } from '../../services/adminAnalyticsService';
import { ApiError } from '../../services/apiClient';
import { adminMonitoringService } from '../../services/adminMonitoringService';
import type {
  AdminActionLogRecord,
  AdminActiveUser,
  AdminAnalyticsOverview,
  AdminHeavyUser,
  AdminSystemHealthDetailed,
  AdminTrafficAnalytics,
  AdminTrafficPeriod,
  AdminWebTrafficAnalytics,
  ViewType,
} from '../../types';
import { formatNumber } from '../../utils/formatters';

const errorMessage = (error: unknown, subject = 'platform analytics') => error instanceof ApiError && error.status === 403
  ? 'Access denied. An administrator account is required.'
  : error instanceof ApiError && error.status === 429
    ? `Too many ${subject} requests. Please wait and try again.`
    : error instanceof ApiError && error.status === 0
      ? 'Unable to reach the API. Check your connection and try again.'
      : `Unable to load ${subject} right now.`;

interface MetricCardProps {
  label: string;
  value: number;
  detail: string;
  icon: React.ElementType;
  warning?: boolean;
}

function MetricCard({ label, value, detail, icon: Icon, warning = false }: MetricCardProps) {
  return (
    <article className="group rounded-xl border border-[#FF8A00]/15 bg-[#071416] px-3.5 py-3 text-[#F8FAFC] shadow-[0_10px_30px_rgba(0,0,0,0.16)] transition-colors hover:border-[#FF8A00]/35">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[8px] font-black uppercase tracking-[0.15em] text-[#94A3B8]">{label}</p>
          <p className={`mt-1 text-2xl font-black tracking-tight ${warning ? 'text-[var(--color-danger-text)]' : 'text-[#F8FAFC]'}`}>{formatNumber(value)}</p>
        </div>
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border ${warning ? 'border-[var(--color-danger-border)]/60 bg-[var(--color-danger-bg)] text-[var(--color-danger-text)]' : 'border-[#FF8A00]/25 bg-[#FF8A00]/10 text-[#FF8A00]'}`}>
          <Icon className="h-3.5 w-3.5" />
        </span>
      </div>
      <p className="mt-1.5 truncate text-[9px] text-[#94A3B8]" title={detail}>{detail}</p>
    </article>
  );
}

const formattedTimestamp = (value: string | null) => {
  if (!value) return 'Not available';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Not available' : date.toLocaleString();
};

const readable = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const healthTone = (status: string) => {
  const normalized = status.toLowerCase();
  if (['ok', 'healthy', 'connected', 'not_required'].includes(normalized)) return 'border-[var(--color-success-border)] bg-[var(--color-success-bg)] text-[var(--color-success-text)]';
  if (['warning', 'degraded', 'reconnecting'].includes(normalized)) return 'border-[var(--color-warning-border)] bg-[var(--color-warning-bg)] text-[var(--color-warning-text)]';
  return 'border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] text-[var(--color-danger-text)]';
};
const operationalCount = (value: number | null) => value === null ? 'N/A' : formatNumber(value);
const compactDateFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: 'short',
  month: 'short',
  day: 'numeric',
  year: 'numeric',
});
const fullDateFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
  year: 'numeric',
});
const timeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: true,
});

const greetingForHour = (hour: number) => {
  if (hour >= 5 && hour < 12) return 'Good morning';
  if (hour >= 12 && hour < 18) return 'Good afternoon';
  return 'Good evening';
};

export const AdminDashboard: React.FC = () => {
  const { currentUser, setCurrentView } = useApp();
  const [overview, setOverview] = useState<AdminAnalyticsOverview | null>(null);
  const [activeUsers, setActiveUsers] = useState<AdminActiveUser[]>([]);
  const [heavyUsers, setHeavyUsers] = useState<AdminHeavyUser[]>([]);
  const [health, setHealth] = useState<AdminSystemHealthDetailed | null>(null);
  const [actionLogs, setActionLogs] = useState<AdminActionLogRecord[]>([]);
  const [traffic, setTraffic] = useState<AdminTrafficAnalytics | null>(null);
  const [trafficPeriod, setTrafficPeriod] = useState<AdminTrafficPeriod>('7d');
  const [webTraffic, setWebTraffic] = useState<AdminWebTrafficAnalytics | null>(null);
  const [webTrafficPeriod, setWebTrafficPeriod] = useState<AdminTrafficPeriod>('30d');
  const [activeUsersError, setActiveUsersError] = useState('');
  const [heavyUsersError, setHeavyUsersError] = useState('');
  const [healthError, setHealthError] = useState('');
  const [actionLogsError, setActionLogsError] = useState('');
  const [trafficError, setTrafficError] = useState('');
  const [webTrafficError, setWebTrafficError] = useState('');
  const [loading, setLoading] = useState(true);
  const [trafficLoading, setTrafficLoading] = useState(true);
  const [webTrafficLoading, setWebTrafficLoading] = useState(true);
  const [error, setError] = useState('');
  const [currentDateTime, setCurrentDateTime] = useState(() => new Date());
  const trafficRequestId = useRef(0);
  const webTrafficRequestId = useRef(0);

  const loadDashboard = useCallback(async () => {
    setLoading(true);
    setError('');
    setActiveUsersError('');
    setHeavyUsersError('');
    setHealthError('');
    setActionLogsError('');

    const [overviewResult, activeUsersResult, heavyUsersResult, healthResult, actionLogsResult] = await Promise.allSettled([
      adminAnalyticsService.overview(),
      adminAnalyticsService.activeUsers(1, 5),
      adminAnalyticsService.heavyUsers({ page: 1, perPage: 5 }),
      adminMonitoringService.systemHealth(),
      adminMonitoringService.actionLogs({ page: 1, perPage: 6 }),
    ]);

    if (overviewResult.status === 'rejected') {
      setError(errorMessage(overviewResult.reason));
      setOverview(null);
    } else setOverview(overviewResult.value);

    if (activeUsersResult.status === 'rejected') {
      setActiveUsers([]);
      setActiveUsersError(errorMessage(activeUsersResult.reason, 'active-user analytics'));
    } else setActiveUsers(activeUsersResult.value.users);

    if (heavyUsersResult.status === 'rejected') {
      setHeavyUsers([]);
      setHeavyUsersError(errorMessage(heavyUsersResult.reason, 'heavy-user analytics'));
    } else setHeavyUsers(heavyUsersResult.value.users);

    if (healthResult.status === 'rejected') {
      setHealth(null);
      setHealthError(errorMessage(healthResult.reason, 'system health'));
    } else setHealth(healthResult.value);

    if (actionLogsResult.status === 'rejected') {
      setActionLogs([]);
      setActionLogsError(errorMessage(actionLogsResult.reason, 'admin activity'));
    } else setActionLogs(actionLogsResult.value.actionLogs);

    setLoading(false);
  }, []);

  const loadTraffic = useCallback(async (period: AdminTrafficPeriod) => {
    const requestId = ++trafficRequestId.current;
    setTrafficLoading(true);
    setTrafficError('');
    try {
      const response = await adminAnalyticsService.traffic(period);
      if (requestId === trafficRequestId.current) setTraffic(response);
    } catch (requestError) {
      if (requestId === trafficRequestId.current) {
        setTraffic(null);
        setTrafficError(errorMessage(requestError, 'platform traffic'));
      }
    } finally {
      if (requestId === trafficRequestId.current) setTrafficLoading(false);
    }
  }, []);

  const loadWebTraffic = useCallback(async (period: AdminTrafficPeriod) => {
    const requestId = ++webTrafficRequestId.current;
    setWebTrafficLoading(true);
    setWebTrafficError('');
    try {
      const response = await adminAnalyticsService.webTraffic(period);
      if (requestId === webTrafficRequestId.current) setWebTraffic(response);
    } catch (requestError) {
      if (requestId === webTrafficRequestId.current) {
        setWebTraffic(null);
        setWebTrafficError(errorMessage(requestError, 'web traffic'));
      }
    } finally {
      if (requestId === webTrafficRequestId.current) setWebTrafficLoading(false);
    }
  }, []);

  useEffect(() => { void loadDashboard(); }, [loadDashboard]);
  useEffect(() => { void loadTraffic(trafficPeriod); }, [loadTraffic, trafficPeriod]);
  useEffect(() => { void loadWebTraffic(webTrafficPeriod); }, [loadWebTraffic, webTrafficPeriod]);
  useEffect(() => {
    const intervalId = window.setInterval(() => setCurrentDateTime(new Date()), 1_000);
    return () => window.clearInterval(intervalId);
  }, []);

  if (loading) return <LoadingState label="Loading real platform analytics..." />;

  if (!overview || error) {
    return (
      <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[#071416] p-7 text-center">
        <AlertCircle className="mx-auto h-7 w-7 text-[var(--color-danger-text)]" />
        <h1 className="mt-3 text-lg font-black">Analytics unavailable</h1>
        <p className="mt-2 text-xs text-[#94A3B8]">{error}</p>
        <button onClick={() => void loadDashboard()} className="mt-4 rounded-lg bg-[#FF8A00] px-4 py-2 text-xs font-black text-[#061113]">Try again</button>
      </div>
    );
  }

  const processingAudits = overview.pendingAudits + overview.runningAudits;
  const adminName = currentUser?.name?.trim() || 'Admin';
  const greeting = greetingForHour(currentDateTime.getHours());
  const compactDate = compactDateFormatter.format(currentDateTime);
  const fullDate = fullDateFormatter.format(currentDateTime);
  const liveTime = timeFormatter.format(currentDateTime);
  const metrics: MetricCardProps[] = [
    { label: 'Total users', value: overview.totalUsers, detail: `${overview.activeUsers} recently active · ${overview.inactiveUsers} inactive`, icon: Users },
    { label: 'API requests · 24h', value: overview.requestsLast24h, detail: `${formatNumber(overview.requestsLast7d)} requests over 7 days`, icon: Activity },
    { label: 'Audits', value: overview.totalAudits, detail: `${overview.completedAudits} completed · ${processingAudits} processing · ${overview.failedAudits} failed`, icon: FileCheck2, warning: overview.failedAudits > 0 },
    { label: 'AI recommendations', value: overview.totalRecommendations, detail: `${overview.adminUsers} administrators · stored recommendations`, icon: Bot },
  ];
  const shortcuts: { label: string; detail: string; view: ViewType; icon: React.ElementType }[] = [
    { label: 'Users', detail: 'Accounts', view: 'users-management', icon: Users },
    { label: 'Audits', detail: 'Jobs', view: 'admin-audits', icon: FileCheck2 },
    { label: 'AI Recommendations', detail: 'Generated output', view: 'admin-recommendations', icon: Bot },
    { label: 'System Health', detail: 'Services', view: 'system-health', icon: Server },
    { label: 'System Logs', detail: 'Sanitized logs', view: 'system-logs', icon: Activity },
  ];
  const healthServices = health ? [
    { label: 'Database', value: health.databaseStatus, icon: Database },
    { label: 'Redis', value: health.redisStatus, icon: HardDrive },
    { label: 'Cache', value: health.cacheStatus, icon: Activity },
    { label: 'Queue', value: health.queueConnection, icon: Workflow },
  ] : [];

  return (
    <div id="admin-dashboard-view" className="mx-auto max-w-[1600px] space-y-3 text-[var(--color-text)]">
      <div className="flex h-7 items-center gap-2 border-b border-white/[0.06] px-1 text-[9px] font-semibold text-[#94A3B8]" aria-label="Current local date and time">
        <time dateTime={currentDateTime.toISOString()}>{compactDate}</time>
        <span className="text-[#FF8A00]" aria-hidden="true">|</span>
        <time dateTime={currentDateTime.toISOString()} className="tabular-nums text-[#F8FAFC]">{liveTime}</time>
        <span className="h-1.5 w-1.5 rounded-full bg-[#FF8A00] shadow-[0_0_8px_rgba(255,138,0,0.7)]" aria-hidden="true" />
      </div>

      <header className="flex flex-col justify-between gap-3 rounded-xl border border-[#FF8A00]/15 bg-[#071416] px-4 py-3 shadow-[0_10px_30px_rgba(0,0,0,0.14)] sm:flex-row sm:items-center">
        <div className="flex min-w-0 items-center gap-3">
          <span className="h-9 w-1 shrink-0 rounded-full bg-[#FF8A00] shadow-[0_0_14px_rgba(255,138,0,0.45)]" />
          <div className="min-w-0">
            <h1 className="truncate text-lg font-black tracking-tight text-[#F8FAFC]">{greeting}, {adminName}.</h1>
            <p className="mt-0.5 flex items-center gap-2 text-[10px] text-[#94A3B8]"><span>{fullDate}</span><span className="h-1 w-1 rounded-full bg-[#FF8A00]" aria-hidden="true" /></p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <p className="hidden text-[9px] text-[#94A3B8] sm:block">Updated {formattedTimestamp(overview.generatedAt)}</p>
          <button onClick={() => { void loadDashboard(); void loadTraffic(trafficPeriod); void loadWebTraffic(webTrafficPeriod); }} className="inline-flex h-8 items-center gap-2 rounded-lg border border-[#FF8A00]/20 bg-[#071416] px-3 text-[10px] font-black text-[#F8FAFC] hover:border-[#FF8A00]/45">
            <RefreshCw className={`h-3.5 w-3.5 text-[#FF8A00] ${loading || trafficLoading || webTrafficLoading ? 'animate-spin' : ''}`} /> Refresh
          </button>
        </div>
      </header>

      <section aria-label="Platform metrics" className="grid grid-cols-2 gap-2 xl:grid-cols-4">
        {metrics.map((metric) => <MetricCard key={metric.label} {...metric} />)}
      </section>

      <section className="grid items-start gap-3 xl:grid-cols-12">
        <div className="min-w-0 xl:col-span-8">
          <WebTrafficOverviewChart
            data={webTraffic}
            period={webTrafficPeriod}
            loading={webTrafficLoading}
            error={webTrafficError}
            onPeriodChange={setWebTrafficPeriod}
            onRetry={() => void loadWebTraffic(webTrafficPeriod)}
          />
        </div>

        <div className="min-w-0 space-y-3 xl:col-span-4">
          <article className="rounded-2xl border border-[#FF8A00]/15 bg-[#071416] p-4 shadow-[0_12px_34px_rgba(0,0,0,0.18)]">
            <StatusDistributionChart statusCounts={{ completed: overview.completedAudits, running: overview.runningAudits, pending: overview.pendingAudits, failed: overview.failedAudits }} />
          </article>

          <article className="rounded-2xl border border-[#FF8A00]/15 bg-[#071416] p-4 shadow-[0_12px_34px_rgba(0,0,0,0.18)]">
            <div className="flex items-center justify-between gap-3"><div><h2 className="text-xs font-black">System health</h2><p className="mt-0.5 text-[9px] text-[#94A3B8]">Protected Laravel checks</p></div><button onClick={() => setCurrentView('system-health')} className="text-[9px] font-black text-[#FF8A00]">Details</button></div>
            {healthError || !health ? <p className="mt-3 rounded-lg border border-white/[0.07] bg-[#081A1C] p-3 text-[10px] text-[#94A3B8]">{healthError || 'System health is not available.'}</p> : (
              <>
                <div className="mt-3 grid grid-cols-2 gap-1.5">
                  {healthServices.map(({ label, value, icon: Icon }) => <div key={label} className="flex items-center justify-between rounded-lg border border-white/[0.06] bg-[#081A1C] px-2.5 py-2"><span className="flex items-center gap-1.5 text-[9px] font-bold text-[#94A3B8]"><Icon className="h-3 w-3 text-[#FF8A00]" />{label}</span><span className={`rounded-full border px-1.5 py-0.5 text-[7px] font-black ${healthTone(value)}`}>{readable(value)}</span></div>)}
                </div>
                <div className="mt-2 grid grid-cols-3 gap-1.5">
                  {[
                    { label: 'Failed jobs', value: health.recentFailedJobs },
                    { label: 'Failed audits', value: health.recentFailedAudits },
                    { label: 'Stale pending', value: health.stalePendingAudits },
                    { label: 'Stale running', value: health.staleRunningAudits },
                    { label: 'API logs · 24h', value: health.accessLogsLast24h },
                  ].map((item) => <div key={item.label} className="rounded-lg bg-[#081A1C] px-2 py-1.5"><p className="truncate text-[7px] font-black uppercase text-[#94A3B8]">{item.label}</p><p className="mt-0.5 text-sm font-black text-[#FF8A00]">{operationalCount(item.value)}</p></div>)}
                </div>
              </>
            )}
          </article>
        </div>
      </section>

      <section className="grid items-start gap-3 xl:grid-cols-12">
        <div className="min-w-0 rounded-2xl border border-[#FF8A00]/15 bg-[#061113] p-4 shadow-[0_12px_34px_rgba(0,0,0,0.18)] xl:col-span-8">
          <PlatformActivityChart data={traffic} period={trafficPeriod} loading={trafficLoading} error={trafficError} onPeriodChange={setTrafficPeriod} onRetry={() => void loadTraffic(trafficPeriod)} />
        </div>

        <article className="overflow-hidden rounded-2xl border border-[#FF8A00]/15 bg-[#071416] shadow-[0_12px_34px_rgba(0,0,0,0.18)] xl:col-span-4">
          <div className="flex items-center justify-between border-b border-white/[0.06] px-4 py-3"><div><h2 className="text-xs font-black">Live platform activity</h2><p className="mt-0.5 text-[9px] text-[#94A3B8]">Real protected semantic actions</p></div><button onClick={() => setCurrentView('admin-action-logs')} className="inline-flex items-center gap-1 text-[9px] font-black text-[#FF8A00]">View all <ArrowUpRight className="h-3 w-3" /></button></div>
          {actionLogsError ? <p className="p-4 text-[10px] text-[#94A3B8]">{actionLogsError}</p> : actionLogs.length === 0 ? <p className="p-4 text-[10px] text-[#94A3B8]">No semantic actions recorded.</p> : (
            <div className="divide-y divide-white/[0.05]">{actionLogs.slice(0, 5).map((log) => <div key={log.id} className="flex items-start gap-2.5 px-4 py-2.5"><span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-[#FF8A00]/10 text-[#FF8A00]"><ShieldCheck className="h-3 w-3" /></span><div className="min-w-0 flex-1"><p className="truncate text-[10px] font-black">{log.action}</p><p className="mt-0.5 truncate text-[8px] text-[#94A3B8]">{log.actorEmail || log.actorName} · {log.entityType || 'system'}{log.entityId ? ` #${log.entityId}` : ''}</p></div><time className="shrink-0 text-[7px] text-[#94A3B8]">{formattedTimestamp(log.createdAt)}</time></div>)}</div>
          )}
        </article>
      </section>

      <section className="grid gap-3 xl:grid-cols-2">
        <article className="overflow-hidden rounded-2xl border border-[#FF8A00]/15 bg-[#071416]">
          <div className="flex items-center justify-between border-b border-white/[0.06] px-4 py-3"><div><h2 className="text-xs font-black">Recently active users</h2><p className="mt-0.5 text-[9px] text-[#94A3B8]">Authenticated API activity · {overview.activeUsersWindowMinutes}m</p></div><button onClick={() => setCurrentView('active-users-analytics')} className="text-[9px] font-black text-[#FF8A00]">View all</button></div>
          {activeUsersError ? <p className="p-4 text-[10px] text-[#94A3B8]">{activeUsersError}</p> : activeUsers.length === 0 ? <p className="p-4 text-[10px] text-[#94A3B8]">No recent authenticated activity.</p> : <div className="divide-y divide-white/[0.05]">{activeUsers.map((user) => <div key={user.id} className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2.5 px-4 py-2"><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-[#081A1C] text-[9px] font-black text-[#FF8A00]">{user.name.charAt(0).toUpperCase() || '?'}</span><div className="min-w-0"><p className="truncate text-[10px] font-bold">{user.name}</p><p className="truncate text-[8px] text-[#94A3B8]">{user.email} · {formattedTimestamp(user.lastSeenAt)}</p></div><div className="text-right"><p className="text-xs font-black text-[#FF8A00]">{formatNumber(user.requestCountLast15m)}</p><p className="text-[7px] uppercase text-[#94A3B8]">requests</p></div></div>)}</div>}
        </article>

        <article className="overflow-hidden rounded-2xl border border-[#FF8A00]/15 bg-[#071416]">
          <div className="flex items-center justify-between border-b border-white/[0.06] px-4 py-3"><div><h2 className="text-xs font-black">Heavy users</h2><p className="mt-0.5 text-[9px] text-[#94A3B8]">Real attributed API usage · 7d</p></div><button onClick={() => setCurrentView('heavy-users-analytics')} className="text-[9px] font-black text-[#FF8A00]">Explore</button></div>
          {heavyUsersError ? <p className="p-4 text-[10px] text-[#94A3B8]">{heavyUsersError}</p> : heavyUsers.length === 0 ? <p className="p-4 text-[10px] text-[#94A3B8]">No attributed API usage in this period.</p> : <div className="divide-y divide-white/[0.05]">{heavyUsers.map((user, index) => <div key={user.id} className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2.5 px-4 py-2"><span className="font-mono text-[9px] font-black text-[#94A3B8]">#{index + 1}</span><div className="min-w-0"><p className="truncate text-[10px] font-bold">{user.name}</p><p className="truncate text-[8px] text-[#94A3B8]">{user.auditsCount} audits · {user.recommendationsCount} recommendations</p></div><div className="text-right"><p className="text-xs font-black text-[#FF8A00]">{formatNumber(user.requestsCount)}</p><p className="text-[7px] uppercase text-[#94A3B8]">requests</p></div></div>)}</div>}
        </article>
      </section>

      <section className="rounded-xl border border-[#FF8A00]/15 bg-[#071416] px-3 py-2.5">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center"><div className="flex shrink-0 items-center gap-2 pr-2"><ShieldCheck className="h-3.5 w-3.5 text-[#FF8A00]" /><p className="text-[9px] font-black uppercase tracking-wider">Operations</p></div><div className="grid flex-1 grid-cols-2 gap-1.5 md:grid-cols-5">{shortcuts.map(({ label, detail, view, icon: Icon }) => <button key={view} onClick={() => setCurrentView(view)} className="flex items-center gap-2 rounded-lg border border-white/[0.06] bg-[#081A1C] px-2.5 py-2 text-left hover:border-[#FF8A00]/30"><Icon className="h-3.5 w-3.5 shrink-0 text-[#FF8A00]" /><span className="min-w-0"><span className="block truncate text-[9px] font-black">{label}</span><span className="block truncate text-[7px] text-[#94A3B8]">{detail}</span></span></button>)}</div></div>
      </section>
    </div>
  );
};
