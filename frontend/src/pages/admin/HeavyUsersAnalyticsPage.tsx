import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Activity, FileCheck, RefreshCw, Sparkles, TriangleAlert, Users } from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { StatusBadge } from '../../components/ui/StatusBadge';
import { adminAnalyticsService } from '../../services/adminAnalyticsService';
import { ApiError } from '../../services/apiClient';
import type { AdminHeavyUser, AdminHeavyUserPeriod, AuditPagination } from '../../types';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

const PERIOD_OPTIONS: { value: AdminHeavyUserPeriod; label: string }[] = [
  { value: '24h', label: 'Last 24 hours' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
];

const errorMessage = (error: unknown) => {
  if (!(error instanceof ApiError)) return 'Unable to load heavy-user analytics.';
  return getPublicApiErrorMessage(error, {
    fallback: 'Unable to load heavy-user analytics right now.',
    validationFallback: 'Check the selected period.',
    forbiddenMessage: 'Access denied. An administrator account is required.',
    notFoundMessage: 'Heavy-user analytics were not found.',
    rateLimitMessage: 'Too many requests. Please wait before refreshing.',
  });
};

const displayLastSeen = (value: string | null) => value ? new Date(value).toLocaleString() : 'No API activity';

export const HeavyUsersAnalyticsPage: React.FC = () => {
  const [users, setUsers] = useState<AdminHeavyUser[]>([]);
  const [pagination, setPagination] = useState<AuditPagination | null>(null);
  const [period, setPeriod] = useState<AdminHeavyUserPeriod>('7d');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [apiActivityAvailable, setApiActivityAvailable] = useState(true);
  const requestId = useRef(0);

  const loadUsers = useCallback(async () => {
    const currentRequestId = ++requestId.current;
    setLoading(true);
    setError('');
    setUsers([]);
    setPagination(null);

    try {
      const response = await adminAnalyticsService.heavyUsers({ page, perPage: 20, period });
      if (currentRequestId !== requestId.current) return;
      setUsers(response.users);
      setPagination(response.pagination);
      setApiActivityAvailable(response.metadata.apiActivityAvailable);
    } catch (requestError) {
      if (currentRequestId !== requestId.current) return;
      setError(errorMessage(requestError));
    } finally {
      if (currentRequestId === requestId.current) setLoading(false);
    }
  }, [page, period]);

  useEffect(() => {
    void loadUsers();
    return () => { requestId.current += 1; };
  }, [loadUsers]);

  const changePeriod = (nextPeriod: AdminHeavyUserPeriod) => {
    setUsers([]);
    setPagination(null);
    setLoading(true);
    setPage(1);
    setPeriod(nextPeriod);
  };

  const totals = users.reduce((result, user) => ({
    requests: result.requests + user.requestsCount,
    audits: result.audits + user.auditsCount,
    recommendations: result.recommendations + user.recommendationsCount,
    errors: result.errors + user.errorRequestsCount,
  }), { requests: 0, audits: 0, recommendations: 0, errors: 0 });

  return (
    <div id="heavy-users-analytics-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
          <h1 className="text-2xl font-black tracking-tight">Heavy Users</h1>
          <p className="mt-1 text-xs text-[var(--color-muted)]">Ranked from real platform usage during the selected period.</p>
        </div>
        <div className="flex items-center gap-2">
          <select
            aria-label="Heavy users period"
            value={period}
            onChange={(event) => changePeriod(event.target.value as AdminHeavyUserPeriod)}
            disabled={loading}
            className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-xs font-bold disabled:opacity-50"
          >
            {PERIOD_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
          <button onClick={() => void loadUsers()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-xs font-bold disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button>
        </div>
      </div>

      {!apiActivityAvailable ? <p className="rounded-xl border border-[var(--color-warning-border)] bg-[var(--color-warning-bg)] px-4 py-3 text-xs text-[var(--color-warning-text)]">API activity is unavailable because authenticated access logs do not include user IDs.</p> : null}
      {loading ? <LoadingState label={`Loading heavy-user analytics for the ${PERIOD_OPTIONS.find((option) => option.value === period)?.label.toLowerCase()}...`} /> : null}
      {!loading && error ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">Heavy-user analytics unavailable</p><p className="mt-1">{error}</p><button onClick={() => void loadUsers()} className="mt-3 inline-flex items-center gap-2 font-bold"><RefreshCw className="h-4 w-4" />Retry</button></div> : null}
      {!loading && !error && users.length === 0 ? <EmptyState title="No usage recorded" description="No registered users generated platform activity during the selected period." /> : null}

      {!loading && !error && users.length > 0 ? <>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[
            { label: 'API requests on this page', value: totals.requests, icon: Activity },
            { label: 'Audits on this page', value: totals.audits, icon: FileCheck },
            { label: 'AI recommendations', value: totals.recommendations, icon: Sparkles },
            { label: 'Error requests', value: totals.errors, icon: TriangleAlert },
          ].map(({ label, value, icon: Icon }) => <div key={label} className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md"><div className="flex items-center justify-between text-xs font-bold uppercase text-[var(--color-muted)]"><span>{label}</span><Icon className="h-4 w-4 text-[var(--color-primary)]" /></div><div className="mt-2 text-3xl font-black">{value.toLocaleString()}</div></div>)}
        </div>

        <div className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl">
          <div className="flex items-center gap-2 border-b border-[var(--color-border)] p-5"><Users className="h-4 w-4 text-[var(--color-primary)]" /><h2 className="text-sm font-bold">Ranked platform consumers</h2></div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1280px] text-left text-xs">
              <thead className="bg-[var(--color-canvas)] text-[10px] uppercase text-[var(--color-muted)]"><tr><th className="px-4 py-3">User ID</th><th className="px-4 py-3">User profile</th><th className="px-4 py-3">Email</th><th className="px-4 py-3">Role</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-center">API requests</th><th className="px-4 py-3 text-center">Audits</th><th className="px-4 py-3 text-center">AI recommendations</th><th className="px-4 py-3 text-center">Errors</th><th className="px-4 py-3">Last seen</th><th className="px-4 py-3 text-right">Usage score</th></tr></thead>
              <tbody className="divide-y divide-[var(--color-border)]/60">{users.map((user, index) => <tr key={user.id} className="hover:bg-[var(--color-surface-muted)]/40"><td className="whitespace-nowrap px-4 py-4"><span className="rounded-md border border-[#FF8A00]/25 bg-[#FF8A00]/10 px-2 py-1 font-mono text-[10px] font-black text-[#FF8A00]">#{user.id}</span></td><td className="px-4 py-4"><div className="font-bold">{user.name}</div><div className="text-[10px] text-[var(--color-muted)]">Rank #{((pagination?.currentPage ?? 1) - 1) * (pagination?.perPage ?? 20) + index + 1}</div></td><td className="max-w-[220px] truncate px-4 py-4 text-[var(--color-muted)]" title={user.email}>{user.email}</td><td className="px-4 py-4"><span className="rounded border border-[var(--color-border)] bg-[var(--color-canvas)] px-2 py-1 text-[10px] font-bold uppercase">{user.role}</span></td><td className="px-4 py-4"><StatusBadge status={user.isActive ? 'active' : 'inactive'} size="sm" /></td><td className="px-4 py-4 text-center font-black text-[var(--color-primary)]">{user.requestsCount.toLocaleString()}</td><td className="px-4 py-4 text-center font-bold">{user.auditsCount.toLocaleString()}</td><td className="px-4 py-4 text-center">{user.recommendationsCount.toLocaleString()}</td><td className="px-4 py-4 text-center text-[var(--color-danger-text)]">{user.errorRequestsCount.toLocaleString()}</td><td className="whitespace-nowrap px-4 py-4 text-[var(--color-muted)]">{displayLastSeen(user.lastSeenAt)}</td><td className="px-4 py-4 text-right text-base font-black text-[#FF8A00]">{user.usageScore.toLocaleString()}</td></tr>)}</tbody>
            </table>
          </div>
        </div>
        <div className="flex items-center justify-between text-xs text-[var(--color-muted)]"><span>{pagination?.total ?? users.length} ranked users</span><div className="flex items-center gap-2"><button disabled={!pagination?.previousPageUrl} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Previous</button><span>Page {pagination?.currentPage ?? 1} of {pagination?.lastPage ?? 1}</span><button disabled={!pagination?.nextPageUrl} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Next</button></div></div>
      </> : null}
    </div>
  );
};
