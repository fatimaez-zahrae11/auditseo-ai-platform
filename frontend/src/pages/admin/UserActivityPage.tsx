import React, { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, Clock3, Route, Search, ShieldCheck } from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { RequestErrorState } from '../../components/ui/RequestErrorState';
import { useApp } from '../../context/AppContext';
import { adminUserService } from '../../services/adminUserService';
import { ApiError } from '../../services/apiClient';
import type { AdminUserActivity } from '../../types';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

export const UserActivityPage: React.FC = () => {
  const { selectedUserId, setSelectedUserId, setCurrentView } = useApp();
  const [activity, setActivity] = useState<AdminUserActivity | null>(null);
  const [isLoading, setIsLoading] = useState(Boolean(selectedUserId));
  const [errorMessage, setErrorMessage] = useState('');
  const [retryKey, setRetryKey] = useState(0);
  const [search, setSearch] = useState('');

  useEffect(() => {
    let isActive = true;
    setActivity(null);
    setErrorMessage('');
    setIsLoading(Boolean(selectedUserId));
    if (!selectedUserId) return undefined;

    adminUserService.activity(selectedUserId)
      .then((response) => {
        if (isActive) setActivity(response);
      })
      .catch((error: unknown) => {
        if (!isActive) return;
        if (error instanceof ApiError && error.status === 403) {
          setCurrentView(error.message === 'Account disabled' ? 'account-disabled' : 'error-403');
          return;
        }
        setErrorMessage(getPublicApiErrorMessage(error, {
          fallback: 'The activity summary could not be loaded. Please try again.',
          notFoundMessage: 'User not found.',
          rateLimitMessage: 'User activity requests are temporarily rate limited. Please wait and try again.',
        }));
      })
      .finally(() => {
        if (isActive) setIsLoading(false);
      });

    return () => { isActive = false; };
  }, [selectedUserId, retryKey, setCurrentView]);

  const filteredRoutes = useMemo(() => (activity?.recentRoutes ?? []).filter((entry) => {
    const query = search.toLowerCase();
    return entry.route.toLowerCase().includes(query)
      || entry.method.toLowerCase().includes(query)
      || String(entry.statusCode).includes(query);
  }), [activity, search]);

  const returnToUsers = () => {
    setSelectedUserId(null);
    setCurrentView('users-management');
  };

  if (!selectedUserId) {
    return <div className="mx-auto max-w-5xl"><EmptyState title="Select a user to inspect activity" description="Open Users Management and choose Activity on a real Laravel account." action={<button type="button" onClick={() => setCurrentView('users-management')} className="rounded-xl bg-[var(--color-primary)] px-4 py-2.5 text-xs font-black text-[var(--color-on-primary)]">Open Users Management</button>} /></div>;
  }

  if (isLoading) return <LoadingState label="Loading user activity..." />;
  if (errorMessage) return <RequestErrorState title={errorMessage === 'User not found.' ? 'User not found' : 'Activity unavailable'} message={errorMessage} onRetry={() => setRetryKey((key) => key + 1)} />;
  if (!activity) return <RequestErrorState title="Activity unavailable" message="No activity summary was returned for this user." />;

  const metrics = [
    ['Requests (24h)', activity.requestCountLast24h],
    ['Requests (7d)', activity.requestCountLast7d],
    ['Total audits', activity.auditsCount],
    ['Completed audits', activity.completedAuditsCount],
    ['Failed audits', activity.failedAuditsCount],
    ['AI recommendations', activity.recommendationsCount],
  ];

  return (
    <div id="user-activity-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><button type="button" onClick={returnToUsers} className="mb-3 inline-flex items-center gap-2 text-xs font-black text-[var(--color-primary)]"><ArrowLeft className="h-3.5 w-3.5" />Users Management</button><h1 className="text-2xl font-black tracking-tight">User Activity</h1><p className="mt-1 text-xs text-[var(--color-muted)]">Safe activity summary for <span className="font-mono text-[var(--color-text)]">{activity.user.email}</span></p></div><div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3 text-xs"><div className="flex items-center gap-2 font-bold"><ShieldCheck className="h-4 w-4 text-[var(--color-primary)]" />Sanitized backend activity</div><p className="mt-1 text-[10px] text-[var(--color-muted)]">No bodies, headers, tokens, cookies, or user-agent payloads.</p></div></div>

      <section className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">{metrics.map(([label, value]) => <div key={label} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-4"><p className="text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]">{label}</p><p className="mt-2 text-xl font-black text-[var(--color-text)]">{value}</p></div>)}</div><div className="mt-4 grid gap-3 sm:grid-cols-2"><div className="rounded-2xl bg-[var(--color-secondary)] p-4"><p className="text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]">Last seen</p><p className="mt-1 text-sm font-bold">{activity.lastSeenAt ? activity.lastSeenAt.replace('T', ' ').split('.')[0] : 'No activity recorded'}</p></div><div className="rounded-2xl bg-[var(--color-secondary)] p-4"><p className="text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]">Last IP</p><p className="mt-1 font-mono text-sm font-bold">{activity.lastIp || 'Not available'}</p></div></div></section>

      <section className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl">
        <div className="flex flex-col justify-between gap-3 border-b border-[var(--color-border)] p-5 sm:flex-row sm:items-center"><div><div className="flex items-center gap-2"><Route className="h-4 w-4 text-[var(--color-primary)]" /><h2 className="text-base font-black">Recent Routes</h2></div><p className="mt-1 text-xs text-[var(--color-muted)]">The ten most recent sanitized access-log route summaries.</p></div><div className="relative w-full sm:w-72"><Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Filter route, method, or status..." className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-10 pr-4 text-xs outline-none focus:border-[var(--color-primary)]" /></div></div>
        <div className="overflow-x-auto"><table className="w-full min-w-[680px] border-collapse text-left text-xs"><thead><tr className="border-b border-[var(--color-border)] bg-[var(--color-canvas)]/70 text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]"><th className="py-3.5 pl-6 pr-4">Route</th><th className="px-4 py-3.5">Method</th><th className="px-4 py-3.5">Status</th><th className="py-3.5 pl-4 pr-6">Timestamp</th></tr></thead><tbody className="divide-y divide-[var(--color-border)]/60">{filteredRoutes.length ? filteredRoutes.map((entry, index) => <tr key={`${entry.createdAt}-${entry.route}-${index}`} className="hover:bg-[var(--color-surface-muted)]/30"><td className="py-4 pl-6 pr-4 font-mono text-[var(--color-text)]">{entry.route}</td><td className="px-4 py-4"><span className="rounded-lg border border-[var(--color-border)] bg-[var(--color-canvas)] px-2 py-1 font-black text-[var(--color-primary)]">{entry.method}</span></td><td className="px-4 py-4 font-mono font-bold">{entry.statusCode || '—'}</td><td className="py-4 pl-4 pr-6 text-[var(--color-muted)]"><span className="inline-flex items-center gap-1.5"><Clock3 className="h-3.5 w-3.5" />{entry.createdAt.replace('T', ' ').split('.')[0]}</span></td></tr>) : <tr><td colSpan={4} className="py-14 text-center text-[var(--color-muted)]">{activity.recentRoutes.length ? 'No routes match this filter.' : 'No access routes have been recorded for this user.'}</td></tr>}</tbody></table></div>
      </section>
    </div>
  );
};
