import React, { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarDays, RefreshCw, Search } from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { StatusBadge } from '../../components/ui/StatusBadge';
import { adminAnalyticsService, type AdminAuditFilters } from '../../services/adminAnalyticsService';
import { ApiError } from '../../services/apiClient';
import type { AdminAuditSummary, AuditPagination, AuditStatus } from '../../types';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

interface FilterForm {
  search: string;
  status: 'all' | AuditStatus;
  userId: string;
  createdFrom: string;
  createdTo: string;
}

interface AppliedFilters extends Omit<FilterForm, 'userId'> {
  userId?: number;
}

const INITIAL_FILTERS: FilterForm = { search: '', status: 'all', userId: '', createdFrom: '', createdTo: '' };
const INITIAL_APPLIED_FILTERS: AppliedFilters = { search: '', status: 'all', createdFrom: '', createdTo: '' };

const errorMessage = (error: unknown) => {
  if (!(error instanceof ApiError)) return 'Unable to load the global audit index.';
  return getPublicApiErrorMessage(error, {
    fallback: 'Unable to load the audit index right now.',
    validationFallback: 'Check the selected audit filters.',
    forbiddenMessage: 'Access denied. An administrator account is required.',
    notFoundMessage: 'The audit index was not found.',
    rateLimitMessage: 'Too many requests. Please wait before refreshing the audit index.',
  });
};

const displayDate = (value: string | null) => value ? new Date(value).toLocaleString() : 'Not available';
const displayScore = (value: number | null) => value === null ? '—' : value;

export const AdminAuditsPage: React.FC = () => {
  const [audits, setAudits] = useState<AdminAuditSummary[]>([]);
  const [pagination, setPagination] = useState<AuditPagination | null>(null);
  const [form, setForm] = useState<FilterForm>(INITIAL_FILTERS);
  const [filters, setFilters] = useState<AppliedFilters>(INITIAL_APPLIED_FILTERS);
  const [filterError, setFilterError] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const auditRequestId = useRef(0);

  const loadAudits = useCallback(async () => {
    const requestId = ++auditRequestId.current;
    setLoading(true);
    setError('');
    const requestFilters: AdminAuditFilters = {
      page,
      perPage: 20,
      search: filters.search || undefined,
      status: filters.status === 'all' ? undefined : filters.status,
      userId: filters.userId || undefined,
      createdFrom: filters.createdFrom || undefined,
      createdTo: filters.createdTo || undefined,
    };
    try {
      const response = await adminAnalyticsService.audits(requestFilters);
      if (requestId === auditRequestId.current) {
        setAudits(response.audits);
        setPagination(response.pagination);
      }
    } catch (requestError) {
      if (requestId === auditRequestId.current) {
        setAudits([]);
        setPagination(null);
        setError(errorMessage(requestError));
      }
    } finally {
      if (requestId === auditRequestId.current) setLoading(false);
    }
  }, [filters, page]);

  useEffect(() => {
    void loadAudits();
    return () => { auditRequestId.current += 1; };
  }, [loadAudits]);

  const applyFilters = (event: React.FormEvent) => {
    event.preventDefault();
    const normalizedUserId = form.userId.trim();
    const ownerUserId = normalizedUserId ? Number(normalizedUserId) : undefined;
    if (normalizedUserId && (!/^\d+$/.test(normalizedUserId) || !Number.isSafeInteger(ownerUserId) || ownerUserId < 1)) {
      setFilterError('Owner user ID must be a number.');
      return;
    }
    if (form.createdFrom && form.createdTo && form.createdFrom > form.createdTo) {
      setFilterError('Date from must be on or before date to.');
      return;
    }

    setFilterError('');
    setPage(1);
    setFilters({ ...form, search: form.search.trim(), userId: ownerUserId });
  };

  const clearFilters = () => {
    setFilterError('');
    setForm(INITIAL_FILTERS);
    setFilters(INITIAL_APPLIED_FILTERS);
    setPage(1);
  };

  return (
    <div id="admin-audits-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div><h1 className="text-2xl font-black tracking-tight">Audit Supervision</h1><p className="mt-1 text-xs text-[var(--color-muted)]">Global read-only index of Laravel audit workloads and diagnostic scores.</p></div>
        <button onClick={() => void loadAudits()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2 text-xs font-bold hover:border-[var(--color-primary)] disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button>
      </div>

      <form onSubmit={applyFilters} className="space-y-4 rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md">
        <div className="grid gap-3 md:grid-cols-12">
          <label className="relative md:col-span-4"><span className="sr-only">Search domain or URL</span><Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input value={form.search} onChange={(event) => setForm((value) => ({ ...value, search: event.target.value }))} placeholder="Search domain or URL" className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-10 pr-4 text-xs outline-none focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/30" /></label>
          <select value={form.status} onChange={(event) => setForm((value) => ({ ...value, status: event.target.value as FilterForm['status'] }))} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs md:col-span-2"><option value="all">All statuses</option><option value="pending">Pending</option><option value="running">Running</option><option value="completed">Completed</option><option value="failed">Failed</option></select>
          <input inputMode="numeric" value={form.userId} onChange={(event) => setForm((value) => ({ ...value, userId: event.target.value }))} placeholder="Owner user ID" className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs md:col-span-2" />
          <label className="relative md:col-span-2"><span className="sr-only">Created from</span><CalendarDays className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input type="date" value={form.createdFrom} onChange={(event) => setForm((value) => ({ ...value, createdFrom: event.target.value }))} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-9 pr-2 text-xs" /></label>
          <label className="relative md:col-span-2"><span className="sr-only">Created to</span><CalendarDays className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input type="date" min={form.createdFrom || undefined} value={form.createdTo} onChange={(event) => setForm((value) => ({ ...value, createdTo: event.target.value }))} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-9 pr-2 text-xs" /></label>
        </div>
        {filterError ? <p role="alert" className="rounded-lg border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] px-3 py-2 text-[10px] font-bold text-[var(--color-danger-text)]">{filterError}</p> : null}
        <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-[10px] text-[var(--color-muted)]">All displayed filters are applied by the backend to the complete paginated audit index.</p><div className="flex gap-2"><button type="button" onClick={clearFilters} disabled={loading} className="rounded-lg border border-[var(--color-border)] px-4 py-2 text-xs font-bold disabled:opacity-50">Clear</button><button type="submit" disabled={loading} className="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-xs font-bold text-[var(--color-on-primary)] disabled:opacity-50">Apply filters</button></div></div>
      </form>

      {loading ? <LoadingState label="Loading the global audit index..." /> : null}
      {!loading && error ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">Audit index unavailable</p><p className="mt-1">{error}</p></div> : null}
      {!loading && !error && audits.length === 0 ? <EmptyState title="No audits found" description="No audit records match the selected backend filters." /> : null}

      {!loading && !error && audits.length > 0 ? <div className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl"><div className="overflow-x-auto"><table id="admin-audits-data-table" className="w-full min-w-[1180px] text-left text-xs"><thead className="bg-[var(--color-canvas)] text-[10px] uppercase text-[var(--color-muted)]"><tr><th className="px-5 py-3">Audit / owner</th><th className="px-4 py-3">Requested / final URL</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Global</th><th className="px-4 py-3">Technical</th><th className="px-4 py-3">Content</th><th className="px-4 py-3">Links</th><th className="px-4 py-3">Performance</th><th className="px-4 py-3">Lifecycle</th></tr></thead><tbody className="divide-y divide-[var(--color-border)]/60">{audits.map((audit) => <tr key={audit.id} className="hover:bg-[var(--color-surface-muted)]/40"><td className="px-5 py-4"><div className="font-mono font-bold">#{audit.id}</div><div className="mt-1 text-[10px] text-[var(--color-muted)]">{audit.user?.email || 'Owner unavailable'}</div></td><td className="max-w-sm px-4 py-4"><div className="truncate font-bold" title={audit.domain?.name || undefined}>{audit.domain?.name || 'Domain unavailable'}</div><div className="truncate text-[10px] text-[var(--color-muted)]" title={audit.requestedUrl}>Requested: {audit.requestedUrl || 'Not available'}</div><div className="mt-1 truncate text-[10px] text-[var(--color-muted)]" title={audit.finalUrl || undefined}>Final: {audit.finalUrl || 'Not available'}</div></td><td className="px-4 py-4"><StatusBadge status={audit.status} size="sm" /></td><td className="px-4 py-4 font-black text-[var(--color-primary)]">{displayScore(audit.globalScore)}</td><td className="px-4 py-4">{displayScore(audit.technicalScore)}</td><td className="px-4 py-4">{displayScore(audit.contentScore)}</td><td className="px-4 py-4">{displayScore(audit.linksScore)}</td><td className="px-4 py-4">{displayScore(audit.performanceScore)}</td><td className="whitespace-nowrap px-4 py-4 text-[var(--color-muted)]"><div>Created {displayDate(audit.createdAt)}</div><div className="mt-1 text-[10px]">{audit.completedAt ? `Completed ${displayDate(audit.completedAt)}` : audit.failedAt ? `Failed ${displayDate(audit.failedAt)}` : 'Outcome not available'}</div></td></tr>)}</tbody></table></div></div> : null}

      {!loading && !error && pagination ? <div className="flex flex-col gap-3 text-xs text-[var(--color-muted)] sm:flex-row sm:items-center sm:justify-between"><span>Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total} audits</span><div className="flex items-center gap-2"><button disabled={!pagination.previousPageUrl} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Previous</button><span>Page {pagination.currentPage} of {pagination.lastPage}</span><button disabled={!pagination.nextPageUrl} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Next</button></div></div> : null}
    </div>
  );
};
