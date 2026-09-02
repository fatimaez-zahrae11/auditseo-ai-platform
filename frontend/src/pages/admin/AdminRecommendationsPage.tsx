import React, { useCallback, useEffect, useState } from 'react';
import { Bot, Eye, RefreshCw, Search } from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { Modal } from '../../components/ui/Modal';
import { adminMonitoringService, type RecommendationFilters } from '../../services/adminMonitoringService';
import type { AdminRecommendationSummary, AuditPagination } from '../../types';
import { adminReadErrorMessage } from '../../utils/adminApiErrors';

interface FormFilters { search: string; userId: string; auditId: string; createdFrom: string; createdTo: string }
const EMPTY_FILTERS: FormFilters = { search: '', userId: '', auditId: '', createdFrom: '', createdTo: '' };

export const AdminRecommendationsPage: React.FC = () => {
  const [recommendations, setRecommendations] = useState<AdminRecommendationSummary[]>([]);
  const [pagination, setPagination] = useState<AuditPagination | null>(null);
  const [form, setForm] = useState<FormFilters>(EMPTY_FILTERS);
  const [filters, setFilters] = useState<FormFilters>(EMPTY_FILTERS);
  const [selected, setSelected] = useState<AdminRecommendationSummary | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [filterError, setFilterError] = useState('');

  const loadRecommendations = useCallback(async () => {
    setLoading(true); setError('');
    const request: RecommendationFilters = { page, perPage: 20, search: filters.search || undefined, userId: filters.userId || undefined, auditId: filters.auditId || undefined, createdFrom: filters.createdFrom || undefined, createdTo: filters.createdTo || undefined };
    try {
      const response = await adminMonitoringService.recommendations(request);
      setRecommendations(response.recommendations); setPagination(response.pagination);
    } catch (requestError) { setError(adminReadErrorMessage(requestError, 'admin recommendations')); }
    finally { setLoading(false); }
  }, [filters, page]);

  useEffect(() => { void loadRecommendations(); }, [loadRecommendations]);
  const applyFilters = (event: React.FormEvent) => {
    event.preventDefault();
    const userId = form.userId.trim();
    const auditId = form.auditId.trim();
    if (userId && (!/^\d+$/.test(userId) || !Number.isSafeInteger(Number(userId)) || Number(userId) < 1)) {
      setFilterError('User ID must be a positive number.');
      return;
    }
    if (auditId && (!/^\d+$/.test(auditId) || !Number.isSafeInteger(Number(auditId)) || Number(auditId) < 1)) {
      setFilterError('Audit ID must be a positive number.');
      return;
    }
    if (form.createdFrom && form.createdTo && form.createdFrom > form.createdTo) {
      setFilterError('Date from must be on or before date to.');
      return;
    }
    setFilterError('');
    setPage(1);
    setFilters({ ...form, search: form.search.trim(), userId, auditId });
  };
  const clearFilters = () => { setFilterError(''); setForm(EMPTY_FILTERS); setFilters(EMPTY_FILTERS); setPage(1); };

  return (
    <div id="admin-recommendations-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div><h1 className="text-2xl font-black tracking-tight">AI Recommendations</h1><p className="mt-1 text-xs text-[var(--color-muted)]">Read-only supervision of sanitized recommendation previews across completed audits.</p></div><button onClick={() => void loadRecommendations()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2 text-xs font-bold disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button></div>

      <form onSubmit={applyFilters} className="space-y-3 rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md">
        <div className="grid gap-3 lg:grid-cols-10"><label className="relative lg:col-span-3"><span className="sr-only">Search URL or user email</span><Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input value={form.search} onChange={(event) => setForm((value) => ({ ...value, search: event.target.value }))} placeholder="Search URL or user email" className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-10 pr-3 text-xs" /></label><input inputMode="numeric" value={form.userId} onChange={(event) => setForm((value) => ({ ...value, userId: event.target.value }))} placeholder="User ID" className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs" /><input inputMode="numeric" value={form.auditId} onChange={(event) => setForm((value) => ({ ...value, auditId: event.target.value }))} placeholder="Audit ID" className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs" /><input type="date" value={form.createdFrom} onChange={(event) => setForm((value) => ({ ...value, createdFrom: event.target.value }))} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs lg:col-span-2" /><input type="date" min={form.createdFrom || undefined} value={form.createdTo} onChange={(event) => setForm((value) => ({ ...value, createdTo: event.target.value }))} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs lg:col-span-2" /></div>
        {filterError ? <p role="alert" className="rounded-lg border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] px-3 py-2 text-[10px] font-bold text-[var(--color-danger-text)]">{filterError}</p> : null}
        <div className="flex justify-end gap-2"><button type="button" onClick={clearFilters} className="rounded-lg border border-[var(--color-border)] px-4 py-2 text-xs font-bold">Clear</button><button className="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-xs font-bold text-[var(--color-on-primary)]">Apply filters</button></div>
      </form>

      {loading ? <LoadingState label="Loading admin recommendations..." /> : null}
      {!loading && error ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">Recommendations unavailable</p><p className="mt-1">{error}</p></div> : null}
      {!loading && !error && recommendations.length === 0 ? <EmptyState title="No recommendations found" description="No recommendation previews match the selected backend filters." /> : null}

      {!loading && !error && recommendations.length > 0 ? <div className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl"><div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead className="bg-[var(--color-canvas)] text-[10px] uppercase text-[var(--color-muted)]"><tr><th className="px-5 py-3">Recommendation</th><th className="px-4 py-3">Audit / URL</th><th className="px-4 py-3">Owner</th><th className="px-4 py-3">Preview</th><th className="px-4 py-3">Created</th><th className="px-4 py-3">View</th></tr></thead><tbody className="divide-y divide-[var(--color-border)]/60">{recommendations.map((item) => <tr key={item.id} className="hover:bg-[var(--color-surface-muted)]/40"><td className="px-5 py-4 font-mono font-bold">#{item.id}</td><td className="max-w-xs px-4 py-4"><div className="font-bold">Audit #{item.auditId}</div><div className="truncate text-[10px] text-[var(--color-muted)]" title={item.audit?.requestedUrl}>{item.audit?.requestedUrl || 'URL unavailable'}</div></td><td className="px-4 py-4 text-[var(--color-muted)]">{item.user?.email || 'Owner unavailable'}</td><td className="max-w-sm px-4 py-4"><p className="line-clamp-2 whitespace-pre-wrap text-[var(--color-muted)]">{item.generatedTextPreview || 'Preview unavailable'}</p></td><td className="whitespace-nowrap px-4 py-4 text-[var(--color-muted)]">{item.createdAt ? new Date(item.createdAt).toLocaleString() : 'Not available'}</td><td className="px-4 py-4"><button onClick={() => setSelected(item)} className="rounded-lg border border-[var(--color-border)] p-2 text-[var(--color-primary)]" aria-label={`View recommendation ${item.id}`}><Eye className="h-4 w-4" /></button></td></tr>)}</tbody></table></div></div> : null}

      {!loading && !error && pagination ? <div className="flex items-center justify-between text-xs text-[var(--color-muted)]"><span>Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total}</span><div className="flex items-center gap-2"><button disabled={!pagination.previousPageUrl} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Previous</button><span>Page {pagination.currentPage} of {pagination.lastPage}</span><button disabled={!pagination.nextPageUrl} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Next</button></div></div> : null}

      <Modal isOpen={Boolean(selected)} onClose={() => setSelected(null)} title="Recommendation preview" maxWidth="lg">{selected ? <div className="space-y-5"><div className="flex items-center gap-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-4"><Bot className="h-5 w-5 text-[var(--color-primary)]" /><div><p className="text-xs font-bold">Audit #{selected.auditId}</p><p className="text-[10px] text-[var(--color-muted)]">{selected.user?.email || 'Owner unavailable'}</p></div></div><p className="whitespace-pre-wrap break-words rounded-2xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-5 text-sm leading-6 text-[var(--color-text)]">{selected.generatedTextPreview || 'Preview unavailable'}</p><p className="text-[10px] text-[var(--color-muted)]">The backend provides a sanitized 300-character preview only. Provider prompts and raw payloads are not requested or displayed.</p></div> : null}</Modal>
    </div>
  );
};
