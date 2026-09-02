import React, { useCallback, useEffect, useRef, useState } from 'react';
import { RefreshCw, Search } from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { adminMonitoringService, type ActionLogFilters } from '../../services/adminMonitoringService';
import type { AdminActionLogRecord, AuditPagination } from '../../types';
import { adminReadErrorMessage } from '../../utils/adminApiErrors';

type RoleFilter = 'all' | 'admin' | 'user' | 'system';
type StatusFilter = 'all' | 'success' | 'failure';

interface FormFilters {
  role: RoleFilter;
  actorUserId: string;
  search: string;
  action: string;
  entityType: string;
  status: StatusFilter;
  dateFrom: string;
  dateTo: string;
}

type AppliedFilters = Omit<ActionLogFilters, 'page' | 'perPage'>;

const EMPTY_FILTERS: FormFilters = {
  role: 'all',
  actorUserId: '',
  search: '',
  action: '',
  entityType: '',
  status: 'all',
  dateFrom: '',
  dateTo: '',
};

const roleClasses: Record<AdminActionLogRecord['actorRole'], string> = {
  admin: 'border-[#FF8A00]/30 bg-[#FF8A00]/10 text-[#FF8A00]',
  user: 'border-[var(--color-primary)]/30 bg-[var(--color-primary)]/10 text-[var(--color-primary)]',
  system: 'border-[var(--color-border)] bg-[var(--color-canvas)] text-[var(--color-muted)]',
};

export const AdminActionLogsPage: React.FC = () => {
  const [logs, setLogs] = useState<AdminActionLogRecord[]>([]);
  const [pagination, setPagination] = useState<AuditPagination | null>(null);
  const [form, setForm] = useState<FormFilters>(EMPTY_FILTERS);
  const [filters, setFilters] = useState<AppliedFilters>({});
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [filterError, setFilterError] = useState('');
  const requestId = useRef(0);

  const loadLogs = useCallback(async () => {
    const currentRequestId = ++requestId.current;
    setLoading(true);
    setError('');
    setLogs([]);
    setPagination(null);

    try {
      const response = await adminMonitoringService.actionLogs({ ...filters, page, perPage: 20 });
      if (currentRequestId !== requestId.current) return;
      setLogs(response.actionLogs);
      setPagination(response.pagination);
    } catch (requestError) {
      if (currentRequestId !== requestId.current) return;
      setError(adminReadErrorMessage(requestError, 'action logs'));
    } finally {
      if (currentRequestId === requestId.current) setLoading(false);
    }
  }, [filters, page]);

  useEffect(() => {
    void loadLogs();
    return () => { requestId.current += 1; };
  }, [loadLogs]);

  const applyFilters = (event: React.FormEvent) => {
    event.preventDefault();
    const normalizedUserId = form.actorUserId.trim();
    const actorUserId = normalizedUserId ? Number(normalizedUserId) : undefined;

    if (normalizedUserId && (!/^\d+$/.test(normalizedUserId) || !Number.isSafeInteger(actorUserId) || actorUserId < 1)) {
      setFilterError('Actor user ID must be a number.');
      return;
    }
    if (form.dateFrom && form.dateTo && form.dateFrom > form.dateTo) {
      setFilterError('Date from must be on or before date to.');
      return;
    }

    setFilterError('');
    setPage(1);
    setFilters({
      role: form.role === 'all' ? undefined : form.role,
      actorUserId,
      search: form.search.trim() || undefined,
      action: form.action.trim() || undefined,
      entityType: form.entityType.trim() || undefined,
      status: form.status === 'all' ? undefined : form.status,
      dateFrom: form.dateFrom || undefined,
      dateTo: form.dateTo || undefined,
    });
  };

  const clearFilters = () => {
    setFilterError('');
    setForm(EMPTY_FILTERS);
    setFilters({});
    setPage(1);
  };

  return (
    <div id="admin-action-logs-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div>
          <h1 className="text-2xl font-black tracking-tight">Action Logs</h1>
          <p className="mt-1 text-xs text-[var(--color-muted)]">Showing real semantic actions performed by admins and users.</p>
          <p className="mt-1 text-[10px] text-[var(--color-muted)]">User actions are recorded from the moment action logging is enabled.</p>
        </div>
        <button onClick={() => void loadLogs()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2 text-xs font-bold disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button>
      </div>

      <form onSubmit={applyFilters} className="space-y-3 rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md">
        <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
          <select value={form.role} onChange={(event) => setForm((value) => ({ ...value, role: event.target.value as RoleFilter }))} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs"><option value="all">All roles</option><option value="admin">Admin</option><option value="user">User</option><option value="system">System</option></select>
          <input inputMode="numeric" value={form.actorUserId} onChange={(event) => setForm((value) => ({ ...value, actorUserId: event.target.value }))} placeholder="Actor user ID" className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs" />
          <label className="relative lg:col-span-2"><span className="sr-only">Search name, email, or action</span><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input value={form.search} onChange={(event) => setForm((value) => ({ ...value, search: event.target.value }))} placeholder="Search name, email, action, or entity" className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-10 pr-3 text-xs" /></label>
          <input value={form.action} onChange={(event) => setForm((value) => ({ ...value, action: event.target.value }))} placeholder="Exact action type" className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs" />
          <input value={form.entityType} onChange={(event) => setForm((value) => ({ ...value, entityType: event.target.value }))} placeholder="Exact entity type" className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs" />
          <select value={form.status} onChange={(event) => setForm((value) => ({ ...value, status: event.target.value as StatusFilter }))} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs"><option value="all">All statuses</option><option value="success">Success</option><option value="failure">Failure</option></select>
          <div className="grid grid-cols-2 gap-2"><label><span className="sr-only">Date from</span><input type="date" value={form.dateFrom} onChange={(event) => setForm((value) => ({ ...value, dateFrom: event.target.value }))} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs" /></label><label><span className="sr-only">Date to</span><input type="date" min={form.dateFrom || undefined} value={form.dateTo} onChange={(event) => setForm((value) => ({ ...value, dateTo: event.target.value }))} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs" /></label></div>
        </div>
        {filterError ? <p role="alert" className="rounded-lg border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] px-3 py-2 text-[10px] font-bold text-[var(--color-danger-text)]">{filterError}</p> : null}
        <div className="flex justify-end gap-2"><button type="button" onClick={clearFilters} disabled={loading} className="rounded-lg border border-[var(--color-border)] px-4 py-2 text-xs font-bold disabled:opacity-50">Clear</button><button type="submit" disabled={loading} className="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-xs font-bold text-[var(--color-on-primary)] disabled:opacity-50">Apply filters</button></div>
      </form>

      {loading ? <LoadingState label="Loading semantic action logs..." /> : null}
      {!loading && error ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">Action logs unavailable</p><p className="mt-1">{error}</p><button onClick={() => void loadLogs()} className="mt-3 inline-flex items-center gap-2 font-bold"><RefreshCw className="h-4 w-4" />Retry</button></div> : null}
      {!loading && !error && logs.length === 0 ? <EmptyState title="No action logs found" description="No real semantic actions match the selected backend filters." /> : null}

      {!loading && !error && logs.length > 0 ? <div className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl"><div className="overflow-x-auto"><table className="w-full min-w-[1120px] text-left text-xs"><thead className="bg-[var(--color-canvas)] text-[10px] uppercase text-[var(--color-muted)]"><tr><th className="px-4 py-3">Time</th><th className="px-4 py-3">Actor</th><th className="px-4 py-3">Role</th><th className="px-4 py-3">User ID</th><th className="px-4 py-3">Action</th><th className="px-4 py-3">Entity</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Details</th></tr></thead><tbody className="divide-y divide-[var(--color-border)]/60">{logs.map((log) => <tr key={log.id} className="hover:bg-[var(--color-surface-muted)]/40"><td className="whitespace-nowrap px-4 py-3 text-[var(--color-muted)]">{log.createdAt ? new Date(log.createdAt).toLocaleString() : 'Not available'}</td><td className="px-4 py-3"><div className="font-bold">{log.actorName}</div><div className="max-w-[220px] truncate text-[10px] text-[var(--color-muted)]" title={log.actorEmail || undefined}>{log.actorEmail || 'No email'}</div></td><td className="px-4 py-3"><span className={`rounded border px-2 py-1 text-[10px] font-bold uppercase ${roleClasses[log.actorRole]}`}>{log.actorRole}</span></td><td className="whitespace-nowrap px-4 py-3 font-mono text-[var(--color-muted)]">{log.actorUserId ? `#${log.actorUserId}` : '—'}</td><td className="px-4 py-3"><span className="rounded border border-[var(--color-border)] bg-[var(--color-canvas)] px-2 py-1 font-mono text-[10px] font-bold text-[var(--color-primary)]">{log.action}</span></td><td className="px-4 py-3"><div className="font-bold">{log.entityType || 'System'}</div><div className="text-[10px] text-[var(--color-muted)]">{log.entityId ? `#${log.entityId}` : 'No entity ID'}</div></td><td className="px-4 py-3"><span className={`rounded border px-2 py-1 text-[10px] font-bold uppercase ${log.status === 'success' ? 'border-[var(--color-success-border)] bg-[var(--color-success-bg)] text-[var(--color-success-text)]' : log.status === 'failure' ? 'border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] text-[var(--color-danger-text)]' : 'border-[var(--color-border)] text-[var(--color-muted)]'}`}>{log.status || 'Not available'}</span></td><td className="max-w-sm px-4 py-3 text-[10px] text-[var(--color-muted)]">{log.details || 'No additional details'}</td></tr>)}</tbody></table></div></div> : null}

      {!loading && !error && pagination ? <div className="flex items-center justify-between text-xs text-[var(--color-muted)]"><span>Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total}</span><div className="flex items-center gap-2"><button disabled={!pagination.previousPageUrl} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Previous</button><span>Page {pagination.currentPage} of {pagination.lastPage}</span><button disabled={!pagination.nextPageUrl} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Next</button></div></div> : null}
    </div>
  );
};
