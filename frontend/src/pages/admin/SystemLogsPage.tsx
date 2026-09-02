import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { FileText, RefreshCw, Search, ShieldCheck } from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { adminMonitoringService } from '../../services/adminMonitoringService';
import type { AdminSystemLogsData } from '../../types';
import { adminReadErrorMessage } from '../../utils/adminApiErrors';

export const SystemLogsPage: React.FC = () => {
  const [data, setData] = useState<AdminSystemLogsData | null>(null);
  const [lines, setLines] = useState(100);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadLogs = useCallback(async () => {
    setLoading(true); setError('');
    try { setData(await adminMonitoringService.systemLogs(lines)); }
    catch (requestError) { setError(adminReadErrorMessage(requestError, 'system logs')); }
    finally { setLoading(false); }
  }, [lines]);

  useEffect(() => { void loadLogs(); }, [loadLogs]);
  const visibleLines = useMemo(() => {
    const query = search.trim().toLowerCase();
    return query ? (data?.lines ?? []).filter((line) => line.toLowerCase().includes(query)) : data?.lines ?? [];
  }, [data, search]);

  return (
    <div id="system-logs-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div><h1 className="text-2xl font-black tracking-tight">System Logs</h1><p className="mt-1 text-xs text-[var(--color-muted)]">Newest sanitized Laravel log lines from the fixed application log source.</p></div><button onClick={() => void loadLogs()} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2 text-xs font-bold disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button></div>

      <div className="flex flex-col gap-3 rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-md sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2 text-xs text-[var(--color-muted)]"><ShieldCheck className="h-4 w-4 text-[var(--color-primary)]" /><span>Backend redaction and line-length limits are active.</span></div>
        <div className="flex flex-wrap gap-2"><label className="relative"><span className="sr-only">Filter returned log text</span><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Filter returned text" className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-9 pr-3 text-xs" /></label><select value={lines} onChange={(event) => setLines(Number(event.target.value))} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-xs"><option value={50}>50 lines</option><option value={100}>100 lines</option><option value={200}>200 lines</option></select></div>
      </div>

      {loading ? <LoadingState label="Loading sanitized system logs..." /> : null}
      {!loading && error ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">System logs unavailable</p><p className="mt-1">{error}</p></div> : null}
      {!loading && !error && visibleLines.length === 0 ? <EmptyState title={data?.lines.length ? 'No matching log lines' : 'No system logs available'} description={data?.lines.length ? 'No returned log line contains the current frontend-only search text.' : data?.note || 'The Laravel application log returned no lines.'} /> : null}

      {!loading && !error && visibleLines.length > 0 ? <div className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl"><div className="flex flex-col gap-2 border-b border-[var(--color-border)] px-5 py-4 text-xs text-[var(--color-muted)] sm:flex-row sm:items-center sm:justify-between"><span className="flex items-center gap-2"><FileText className="h-4 w-4 text-[var(--color-primary)]" />{visibleLines.length} of {data?.count ?? visibleLines.length} returned lines</span><span>{data?.generatedAt ? `Generated ${new Date(data.generatedAt).toLocaleString()}` : 'Generation time unavailable'}</span></div><ol className="max-h-[65vh] overflow-auto bg-[var(--color-canvas)] p-4 font-mono text-[11px] leading-5"><>{visibleLines.map((line, index) => <li key={`${index}-${line.slice(0, 24)}`} className="grid grid-cols-[2.5rem_1fr] gap-3 border-b border-[var(--color-border)]/30 py-2 last:border-0"><span className="select-none text-right text-[var(--color-muted)]/60">{index + 1}</span><span className="whitespace-pre-wrap break-all text-[var(--color-text)]">{line}</span></li>)}</></ol>{data?.note ? <p className="border-t border-[var(--color-border)] px-5 py-3 text-[10px] text-[var(--color-muted)]">{data.note}</p> : null}</div> : null}
    </div>
  );
};
