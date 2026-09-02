import React, { useEffect, useState } from 'react';
import { ArrowRight, Filter, Globe, PlusCircle, Search } from 'lucide-react';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { LoadingState } from '../../components/ui/LoadingState';
import { PageHeader } from '../../components/ui/PageHeader';
import { RequestErrorState } from '../../components/ui/RequestErrorState';
import { StatusBadge } from '../../components/ui/StatusBadge';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';
import { auditService } from '../../services/auditService';
import type { AuditPagination, AuditStatus, SeoAudit } from '../../types';

type AuditStatusFilter = 'all' | AuditStatus;

interface AuditFilterForm {
  search: string;
  status: AuditStatusFilter;
}

const emptyFilters: AuditFilterForm = { search: '', status: 'all' };

const initialPagination: AuditPagination = {
  currentPage: 1,
  lastPage: 1,
  perPage: 20,
  total: 0,
  from: null,
  to: null,
  previousPageUrl: null,
  nextPageUrl: null,
};

export const MyAuditsPage: React.FC = () => {
  const { selectAudit, setCurrentView } = useApp();
  const [audits, setAudits] = useState<SeoAudit[]>([]);
  const [pagination, setPagination] = useState<AuditPagination>(initialPagination);
  const [page, setPage] = useState(1);
  const [retryKey, setRetryKey] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState('');
  const [filterForm, setFilterForm] = useState<AuditFilterForm>(emptyFilters);
  const [filters, setFilters] = useState<AuditFilterForm>(emptyFilters);

  useEffect(() => {
    let active = true;
    setIsLoading(true);
    setErrorMessage('');

    auditService.listAudits(page, {
      search: filters.search || undefined,
      status: filters.status === 'all' ? undefined : filters.status,
    })
      .then((response) => {
        if (!active) return;
        setAudits(response.audits);
        setPagination(response.pagination);
      })
      .catch((error: unknown) => {
        if (!active) return;
        if (error instanceof ApiError && error.status === 403) {
          if (error.message === 'Account disabled') setCurrentView('account-disabled');
          else if (error.message.toLowerCase().includes('verif')) setCurrentView('email-verification');
          else setCurrentView('error-403');
          return;
        }
        setErrorMessage(error instanceof ApiError && error.status === 429
          ? 'Audit history is temporarily rate limited. Please wait a moment and refresh.'
          : error instanceof ApiError && error.status === 0
            ? 'The audit service is unreachable. Check your connection and try again.'
            : 'Your audit history could not be loaded. Please try again.');
      })
      .finally(() => {
        if (active) setIsLoading(false);
      });

    return () => { active = false; };
  }, [filters, page, retryKey, setCurrentView]);

  const applyFilters = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setPage(1);
    setFilters({ ...filterForm, search: filterForm.search.trim() });
  };

  const clearFilters = () => {
    setFilterForm(emptyFilters);
    setFilters(emptyFilters);
    setPage(1);
  };

  const columns: DataTableColumn<SeoAudit>[] = [
    {
      key: 'domain',
      header: 'Website',
      render: (audit) => (
        <div className="flex items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] text-[var(--color-primary)]"><Globe className="h-4 w-4" /></span>
          <div className="min-w-0"><div className="font-bold text-[var(--color-text)]">{audit.domain}</div><div className="max-w-sm truncate font-mono text-[10px] text-[var(--color-muted)]">{audit.requestedUrl}</div></div>
        </div>
      ),
    },
    { key: 'status', header: 'Status', render: (audit) => <StatusBadge status={audit.status} size="sm" /> },
    { key: 'score', header: 'Scores', align: 'center', render: (audit) => audit.status === 'completed' ? <div><span className="rounded-lg border border-[var(--color-primary)]/40 bg-[var(--color-primary)]/15 px-2.5 py-1 font-black text-[var(--color-primary)]">{audit.globalScore}/100</span><div className="mt-2 whitespace-nowrap text-[9px] font-semibold text-[var(--color-muted)]">T {audit.technicalScore} · C {audit.contentScore} · L {audit.linksScore} · P {audit.performanceScore}</div></div> : '—' },
    { key: 'issues', header: 'Issues', align: 'center', render: (audit) => audit.status === 'completed' ? audit.issues.length : '—' },
    { key: 'createdAt', header: 'Created', render: (audit) => audit.createdAt.split('T')[0] },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (audit) => <button onClick={(event) => { event.stopPropagation(); selectAudit(audit.id); }} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 font-bold text-[var(--color-primary)] hover:bg-[var(--color-surface-muted)]">View report <ArrowRight className="h-3.5 w-3.5" /></button>,
    },
  ];

  return (
    <div id="my-audits-view" className="mx-auto max-w-6xl space-y-4">
      <PageHeader
        eyebrow="Audit history"
        title="My Audits"
        description="Open a previous audit to view its status, SEO scores, issues, and recommendations."
        dense
        actions={<button onClick={() => setCurrentView('user-home')} className="inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--color-cta)] px-4 py-2.5 text-xs font-black text-[var(--color-on-cta)] shadow-[0_10px_24px_rgba(255,138,0,0.16)] hover:bg-[var(--color-cta-hover)]"><PlusCircle className="h-4 w-4" />Start new audit</button>}
      />

      <form onSubmit={applyFilters} className="user-panel flex flex-col gap-3 rounded-2xl p-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="w-full lg:max-w-sm"><div className="relative"><Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input value={filterForm.search} onChange={(event) => setFilterForm((value) => ({ ...value, search: event.target.value }))} placeholder="Search all audits by domain or URL" className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2.5 pl-10 pr-4 text-xs text-[var(--color-text)] outline-none focus:border-[var(--color-primary)]" /></div><p className="mt-1.5 text-[10px] text-[var(--color-muted)]">Search and status are applied to your complete audit history.</p></div>
        <div className="flex flex-wrap items-center gap-2 overflow-x-auto"><span className="flex shrink-0 items-center gap-1 text-xs font-semibold text-[var(--color-muted)]"><Filter className="h-3.5 w-3.5" />Status</span>{(['all', 'completed', 'running', 'pending', 'failed'] as AuditStatusFilter[]).map((status) => <button type="button" key={status} onClick={() => setFilterForm((value) => ({ ...value, status }))} className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-bold capitalize ${filterForm.status === status ? 'bg-[var(--color-primary)] text-[var(--color-on-primary)]' : 'bg-[var(--color-surface-muted)]/50 text-[var(--color-muted)]'}`}>{status}</button>)}<button type="button" onClick={clearFilters} disabled={isLoading} className="shrink-0 rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs font-bold disabled:opacity-50">Clear</button><button type="submit" disabled={isLoading} className="shrink-0 rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-xs font-bold text-[var(--color-on-primary)] disabled:opacity-50">Apply filters</button></div>
      </form>

      {isLoading ? <LoadingState label="Loading your audits..." /> : errorMessage ? <RequestErrorState title="Audit history unavailable" message={errorMessage} onRetry={() => setRetryKey((key) => key + 1)} retryLabel="Retry" /> : (
        <section className="user-panel overflow-hidden rounded-2xl p-2 sm:p-3">
          <DataTable columns={columns} rows={audits} rowKey={(audit) => audit.id} onRowClick={(audit) => selectAudit(audit.id)} emptyTitle={filters.search || filters.status !== 'all' ? 'No matching audits' : 'No audits yet'} emptyDescription={filters.search || filters.status !== 'all' ? 'No audits match the selected backend filters.' : 'Analyze your first website from the Audit Workspace.'} dense />
          {pagination.lastPage > 1 ? <div className="flex flex-col items-center justify-between gap-3 border-t border-[var(--color-border)] p-4 text-xs text-[var(--color-muted)] sm:flex-row"><span>Showing <strong className="text-[var(--color-text)]">{pagination.from ?? 0}–{pagination.to ?? 0}</strong> of <strong className="text-[var(--color-text)]">{pagination.total}</strong></span><div className="flex items-center gap-2"><button disabled={!pagination.previousPageUrl} onClick={() => setPage((current) => Math.max(1, current - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 font-bold disabled:opacity-35">Previous</button><span>Page {pagination.currentPage} of {pagination.lastPage}</span><button disabled={!pagination.nextPageUrl} onClick={() => setPage((current) => current + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 font-bold disabled:opacity-35">Next</button></div></div> : null}
        </section>
      )}
    </div>
  );
};
