import React, { useCallback, useEffect, useState } from 'react';
import { Activity, Clock, RefreshCw, UserCheck, Users } from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { adminAnalyticsService } from '../../services/adminAnalyticsService';
import { ApiError } from '../../services/apiClient';
import type { AdminActiveUser, AuditPagination } from '../../types';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

const errorMessage = (error: unknown) => {
  if (!(error instanceof ApiError)) return 'Unable to load active-user analytics.';
  return getPublicApiErrorMessage(error, {
    fallback: 'Unable to load active-user analytics right now.',
    validationFallback: 'The analytics request was rejected.',
    forbiddenMessage: 'Access denied. An administrator account is required.',
    notFoundMessage: 'Active-user analytics were not found.',
    rateLimitMessage: 'Too many requests. Please wait before refreshing.',
  });
};

export const ActiveUsersAnalyticsPage: React.FC = () => {
  const [users, setUsers] = useState<AdminActiveUser[]>([]);
  const [pagination, setPagination] = useState<AuditPagination | null>(null);
  const [metadata, setMetadata] = useState({ windowMinutes: 15, definition: '' });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadUsers = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const response = await adminAnalyticsService.activeUsers(page, 20);
      setUsers(response.users);
      setPagination(response.pagination);
      setMetadata({ windowMinutes: response.metadata.windowMinutes, definition: response.metadata.definition });
    } catch (requestError) {
      setError(errorMessage(requestError));
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => { void loadUsers(); }, [loadUsers]);

  if (loading) return <LoadingState label="Loading active-user analytics..." />;

  const totalRequests15m = users.reduce((sum, user) => sum + user.requestCountLast15m, 0);
  const totalRequests24h = users.reduce((sum, user) => sum + user.requestCountLast24h, 0);
  const peak = Math.max(1, ...users.map((user) => user.requestCountLast15m));

  return (
    <div id="active-users-analytics-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div>
          <h1 className="text-2xl font-black tracking-tight">Active Users</h1>
          <p className="mt-1 text-xs text-[var(--color-muted)]">Activity recorded by the Laravel API during the last {metadata.windowMinutes} minutes.</p>
        </div>
        <button onClick={() => void loadUsers()} className="inline-flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2 text-xs font-bold hover:border-[var(--color-primary)]">
          <RefreshCw className="h-4 w-4" /> Refresh
        </button>
      </div>

      {error ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">Active-user analytics unavailable</p><p className="mt-1">{error}</p></div> : null}
      {!error && users.length === 0 ? <EmptyState title="No active users" description={`No authenticated API activity was recorded in the last ${metadata.windowMinutes} minutes.`} /> : null}

      {!error && users.length > 0 ? <>
        <div className="grid gap-4 sm:grid-cols-3">
          {[
            { label: 'Active accounts', value: pagination?.total ?? users.length, icon: UserCheck },
            { label: `Requests / ${metadata.windowMinutes}m`, value: totalRequests15m, icon: Activity },
            { label: 'Requests / 24h (page)', value: totalRequests24h, icon: Clock },
          ].map(({ label, value, icon: Icon }) => <div key={label} className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md"><div className="flex items-center justify-between text-xs font-bold uppercase text-[var(--color-muted)]"><span>{label}</span><Icon className="h-4 w-4 text-[var(--color-primary)]" /></div><div className="mt-2 text-3xl font-black">{value.toLocaleString()}</div></div>)}
        </div>

        <div className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-xl">
          <div className="mb-5 flex items-center gap-2"><Activity className="h-4 w-4 text-[var(--color-primary)]" /><h2 className="text-sm font-bold">Current activity by user</h2></div>
          <div className="space-y-3">{users.map((user) => <div key={user.id} className="grid grid-cols-[minmax(8rem,1fr)_2fr_auto] items-center gap-3 text-xs"><span className="truncate font-semibold">{user.email}</span><div className="h-2.5 overflow-hidden rounded-full bg-[var(--color-canvas)]"><div className="h-full rounded-full bg-[var(--color-primary)]" style={{ width: `${Math.max(3, (user.requestCountLast15m / peak) * 100)}%` }} /></div><span className="w-16 text-right font-mono text-[var(--color-muted)]">{user.requestCountLast15m} req</span></div>)}</div>
        </div>

        <div className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl">
          <div className="flex items-center gap-2 border-b border-[var(--color-border)] p-5"><Users className="h-4 w-4 text-[var(--color-primary)]" /><h2 className="text-sm font-bold">Active cohort</h2></div>
          <div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead className="bg-[var(--color-canvas)] text-[11px] uppercase text-[var(--color-muted)]"><tr><th className="px-5 py-3">User</th><th className="px-4 py-3">Role</th><th className="px-4 py-3">Last seen</th><th className="px-4 py-3">Requests 15m</th><th className="px-4 py-3">Requests 24h</th></tr></thead><tbody className="divide-y divide-[var(--color-border)]/60">{users.map((user) => <tr key={user.id} className="hover:bg-[var(--color-surface-muted)]/40"><td className="px-5 py-4"><div className="font-bold">{user.name}</div><div className="text-[10px] text-[var(--color-muted)]">{user.email}</div></td><td className="px-4 py-4 uppercase text-[var(--color-muted)]">{user.role}</td><td className="px-4 py-4 text-[var(--color-muted)]">{user.lastSeenAt ? new Date(user.lastSeenAt).toLocaleString() : 'Not available'}</td><td className="px-4 py-4 font-bold">{user.requestCountLast15m}</td><td className="px-4 py-4 font-bold">{user.requestCountLast24h}</td></tr>)}</tbody></table></div>
        </div>
        <div className="flex flex-col gap-3 text-xs text-[var(--color-muted)] sm:flex-row sm:items-center sm:justify-between"><span>{metadata.definition || `Activity-based users within a ${metadata.windowMinutes}-minute window.`}</span><div className="flex items-center gap-2"><button disabled={!pagination?.previousPageUrl} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Previous</button><span>Page {pagination?.currentPage ?? 1} of {pagination?.lastPage ?? 1}</span><button disabled={!pagination?.nextPageUrl} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Next</button></div></div>
      </> : null}
    </div>
  );
};
