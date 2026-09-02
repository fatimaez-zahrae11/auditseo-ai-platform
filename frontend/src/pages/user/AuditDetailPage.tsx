import React, { useEffect, useState } from 'react';
import { useApp } from '../../context/AppContext';
import {
  ArrowLeft,
  Download,
  RotateCw,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Loader2,
  ShieldCheck,
} from 'lucide-react';
import { ScoreCircle } from '../../components/ui/ScoreCircle';
import { ScoreCard } from '../../components/ui/ScoreCard';
import { StatusBadge } from '../../components/ui/StatusBadge';
import { SeverityBadge } from '../../components/ui/SeverityBadge';
import type { IssueCategory, IssueSeverity, SeoIssue } from '../../types';
import { formatDuration } from '../../utils/formatters';
import { LoadingState } from '../../components/ui/LoadingState';
import { RequestErrorState } from '../../components/ui/RequestErrorState';
import { useAuditPolling } from '../../utils/useAuditPolling';
import { ApiError } from '../../services/apiClient';
import { auditService } from '../../services/auditService';
import { AuditJourney } from '../../components/ui/AuditJourney';
import { RecommendationPanel } from '../../components/ui/RecommendationPanel';
import { getPublicApiErrorMessage, PUBLIC_AUDIT_FAILURE_MESSAGE } from '../../utils/publicApiErrors';

interface IssueCardProps {
  issue: SeoIssue;
  isExpanded: boolean;
  onToggle: () => void;
}

function IssueCard({ issue, isExpanded, onToggle }: IssueCardProps) {
  return (
    <article
      id={`issue-card-${issue.id}`}
      className="overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] transition-colors hover:border-[var(--color-primary)]/45"
    >
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full cursor-pointer items-center justify-between gap-4 bg-[var(--color-surface)] p-4 text-left transition-colors hover:bg-[var(--color-surface-muted)]"
        aria-expanded={isExpanded}
      >
        <div className="flex items-start gap-3 sm:items-center">
          <SeverityBadge severity={issue.severity} size="sm" />
          <div>
            <h4 className="text-sm font-bold text-[var(--color-text)]">{issue.title}</h4>
            <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-[var(--color-muted)]">
              <span className="font-medium capitalize">{issue.category} SEO</span>
              {issue.location ? <span className="font-mono text-[11px]">· {issue.location}</span> : null}
            </div>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-3">
          <span className="hidden text-xs font-bold text-[var(--color-muted)] sm:inline">Impact: -{issue.impactScore} pts</span>
          {isExpanded ? <ChevronUp className="h-4 w-4 text-[var(--color-muted)]" /> : <ChevronDown className="h-4 w-4 text-[var(--color-muted)]" />}
        </div>
      </button>

      {isExpanded ? (
        <div className="space-y-4 border-t border-[var(--color-border)] bg-[#050A0B]/65 p-4 sm:p-5">
          <div>
            <h5 className="mb-1 text-xs font-bold uppercase tracking-wider text-[var(--color-muted)]">Issue Description</h5>
            <p className="text-xs leading-relaxed text-[var(--color-text)] sm:text-sm">{issue.description}</p>
          </div>
          <div className="space-y-1.5 rounded-xl border border-[var(--color-primary)]/40 bg-[var(--color-surface)] p-4">
            <div className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-[var(--color-primary)]">
              <CheckCircle2 className="h-3.5 w-3.5" />
              <span>Recommended Fix</span>
            </div>
            <p className="text-xs font-medium leading-relaxed text-[var(--color-text)] sm:text-sm">{issue.recommendation}</p>
          </div>
          {issue.codeSnippet ? (
            <div>
              <h5 className="mb-1 text-xs font-bold uppercase tracking-wider text-[var(--color-muted)]">Code Reference</h5>
              <pre className="overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-3 font-mono text-xs text-[var(--color-text)]">{issue.codeSnippet}</pre>
            </div>
          ) : null}
        </div>
      ) : null}
    </article>
  );
}

export const AuditDetailPage: React.FC = () => {
  const { addToast, selectedAuditId, setCurrentView, selectAudit } = useApp();
  const { audit: selectedAudit, isLoading, error, retry } = useAuditPolling(selectedAuditId);

  const [selectedCategory, setSelectedCategory] = useState<IssueCategory | 'all'>('all');
  const [selectedSeverity, setSelectedSeverity] = useState<IssueSeverity | 'all'>('all');
  const [expandedIssueId, setExpandedIssueId] = useState<string | null>(null);

  useEffect(() => {
    if (!(error instanceof ApiError) || error.status !== 403) return;
    if (error.message === 'Account disabled') setCurrentView('account-disabled');
    else if (error.message.toLowerCase().includes('verif')) setCurrentView('email-verification');
    else setCurrentView('error-403');
  }, [error, setCurrentView]);

  if (isLoading) return <LoadingState label="Loading audit details..." />;

  if (error) {
    const notFound = error instanceof ApiError && error.status === 404;
    return <RequestErrorState title={notFound ? 'Audit not found' : 'Audit unavailable'} message={getPublicApiErrorMessage(error, { fallback: 'The audit details could not be loaded. Please return to your history and try again.', notFoundMessage: 'This audit does not exist or does not belong to your account.', rateLimitMessage: 'Audit status checks are temporarily rate limited. Please wait a moment and retry.' })} onRetry={retry} retryLabel="Retry" />;
  }

  if (!selectedAudit) {
    return (
      <div className="user-panel mx-auto max-w-xl rounded-2xl p-8 text-center">
        <h3 className="text-lg font-bold text-[var(--color-text)]">No Audit Selected</h3>
        <p className="text-sm text-[var(--color-muted)] mt-1">Please select an audit from your audits list.</p>
        <button
          onClick={() => setCurrentView('my-audits')}
          className="mt-4 px-4 py-2 text-xs font-bold bg-[var(--color-primary)] text-[var(--color-on-primary)] rounded-xl hover:bg-[var(--color-primary-hover)]"
        >
          Go to My Audits
        </button>
      </div>
    );
  }

  const handleRerun = async () => {
    if (!selectedAudit) return;
    try {
      const response = await auditService.createAudit(selectedAudit.requestedUrl);
      addToast({ title: 'Audit Queued', message: 'Audit queued successfully. Processing has started.', type: 'success' });
      selectAudit(response.audit.id);
    } catch (requestError) {
      addToast({ title: 'Unable to Queue Audit', message: requestError instanceof ApiError && requestError.status === 429 ? 'The audit queue rate limit has been reached.' : 'Please try again when the audit service is available.', type: 'error' });
    }
  };

  if (selectedAudit.status === 'pending' || selectedAudit.status === 'running') {
    return (
      <div className="mx-auto max-w-5xl space-y-5">
        <div className="flex items-center gap-3"><button onClick={() => setCurrentView('my-audits')} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-2 text-[var(--color-muted)]"><ArrowLeft className="h-4 w-4" /></button><div><div className="flex items-center gap-2"><h1 className="text-xl font-black text-[var(--color-text)]">{selectedAudit.domain}</h1><StatusBadge status={selectedAudit.status} size="sm" /></div><p className="mt-1 font-mono text-xs text-[var(--color-muted)]">{selectedAudit.requestedUrl}</p></div></div>
        <section className="user-panel-elevated rounded-2xl p-6 text-center sm:p-8">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--color-highlight)] text-[var(--color-on-highlight)] shadow-[0_12px_28px_rgba(255,138,0,0.18)]"><Loader2 className="h-6 w-6 animate-spin" /></div>
          <h2 className="mt-5 text-2xl font-black text-[var(--color-text)]">{selectedAudit.status === 'pending' ? 'Audit queued securely' : 'SEO analysis in progress'}</h2>
          <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--color-muted)]">This page refreshes the audit status automatically every four seconds. You can leave safely and return from My Audits at any time.</p>
          <div className="mt-7 text-left"><AuditJourney auditStatus={selectedAudit.status} hasRecommendation={false} /></div>
        </section>
        <RecommendationPanel auditId={selectedAudit.id} auditStatus={selectedAudit.status} />
      </div>
    );
  }

  if (selectedAudit.status === 'failed') {
    return (
      <div className="mx-auto max-w-3xl space-y-5">
        <button onClick={() => setCurrentView('my-audits')} className="inline-flex items-center gap-2 text-xs font-black text-[var(--color-primary)]"><ArrowLeft className="h-4 w-4" />Back to My Audits</button>
        <RequestErrorState title="Audit processing failed" message={selectedAudit.failureMessage || PUBLIC_AUDIT_FAILURE_MESSAGE} onRetry={() => void handleRerun()} />
        <RecommendationPanel auditId={selectedAudit.id} auditStatus={selectedAudit.status} />
      </div>
    );
  }

  const filteredIssues = selectedAudit.issues.filter((issue) => {
    const matchesCategory = selectedCategory === 'all' || issue.category === selectedCategory;
    const matchesSeverity = selectedSeverity === 'all' || issue.severity === selectedSeverity;
    return matchesCategory && matchesSeverity;
  });

  const criticalIssuesCount = selectedAudit.issues.filter((i) => i.severity === 'critical').length;
  const highIssuesCount = selectedAudit.issues.filter((i) => i.severity === 'high').length;
  const rawData = selectedAudit.rawData ?? {};
  const extractedMetrics = [
    ['Page title', rawData.title],
    ['Meta description', rawData.meta_description],
    ['HTTP status', rawData.http_status_code],
    ['Visible words', rawData.word_count],
    ['H1 headings', rawData.h1_count],
    ['H2 headings', rawData.h2_count],
    ['Internal links', rawData.internal_links_count],
    ['External links', rawData.external_links_count],
    ['HTTPS enabled', rawData.uses_https],
    ['robots.txt found', rawData.robots_txt_found],
    ['sitemap.xml found', rawData.sitemap_xml_found],
    ['Response time', rawData.response_time_ms === undefined ? undefined : `${String(rawData.response_time_ms)} ms`],
  ].filter((entry): entry is [string, string | number | boolean] => (
    typeof entry[1] === 'string' || typeof entry[1] === 'number' || typeof entry[1] === 'boolean'
  ));

  return (
    <div id="audit-detail-view" className="mx-auto max-w-7xl space-y-5">
      {/* Top Breadcrumb & Action Bar */}
      <div className="flex flex-col justify-between gap-4 border-b border-[var(--color-border)] pb-4 sm:flex-row sm:items-center">
        <div className="flex items-center gap-3">
          <button
            id="audit-detail-back-btn"
            onClick={() => setCurrentView('my-audits')}
            className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-2 text-[var(--color-muted)] transition-colors hover:border-[var(--color-primary)]/40 hover:text-[var(--color-text)]"
            title="Back to My Audits"
          >
            <ArrowLeft className="w-4 h-4" />
          </button>
          <div>
            <div className="flex items-center gap-2 flex-wrap">
              <h1 className="text-xl sm:text-2xl font-extrabold text-[var(--color-text)] tracking-tight">
                {selectedAudit.domain}
              </h1>
              <StatusBadge status={selectedAudit.status} size="sm" />
            </div>
            <p className="text-xs text-[var(--color-muted)] font-mono mt-0.5">{selectedAudit.requestedUrl}</p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 sm:gap-3">
          <button
            id="audit-detail-rerun-btn"
            onClick={() => void handleRerun()}
            className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-3.5 py-2 text-xs font-semibold text-[var(--color-muted)] transition-colors hover:border-[var(--color-primary)]/40 hover:text-[var(--color-text)]"
          >
            <RotateCw className="w-3.5 h-3.5" />
            <span>Rerun Audit</span>
          </button>

          <button
            id="audit-detail-pdf-btn"
            onClick={() => selectAudit(selectedAudit.id, 'audit-report')}
            className="inline-flex items-center gap-1.5 rounded-xl bg-[var(--color-primary)] px-4 py-2 text-xs font-bold text-[var(--color-on-primary)] shadow-[0_10px_24px_rgba(255,138,0,0.16)] transition-colors hover:bg-[var(--color-primary-hover)]"
          >
            <Download className="w-3.5 h-3.5" />
            <span>Export PDF</span>
          </button>
        </div>
      </div>

      {/* Overview Hero Section: Big Circular Score + Metadata + 4 Breakdown Cards */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* Left Column: Big Global Score Circle + Metadata (5 cols) */}
        <div className="user-panel-elevated flex flex-col justify-between rounded-2xl p-5 sm:p-6 lg:col-span-4">
          <div>
            <div className="flex items-center justify-between pb-4 border-b border-[var(--color-border)]">
              <span className="text-xs font-bold uppercase tracking-wider text-[var(--color-muted)]">
                SEO Health Score
              </span>
              <span className="text-xs font-mono text-[var(--color-muted)]">{selectedAudit.id}</span>
            </div>

            <div className="flex justify-center py-5">
              <ScoreCircle
                score={selectedAudit.globalScore}
                size="xl"
                showLabel={true}
                label="Overall SEO Health"
              />
            </div>
          </div>

          <dl className="space-y-2.5 border-t border-[var(--color-border)] pt-4 text-xs">
            <div className="flex justify-between items-center py-1">
              <span className="text-[var(--color-muted)]">Requested URL</span>
              <span className="font-semibold text-[var(--color-text)] truncate max-w-[180px]">
                {selectedAudit.requestedUrl}
              </span>
            </div>
            <div className="flex justify-between items-center py-1">
              <span className="text-[var(--color-muted)]">Final URL</span>
              <span className="font-semibold text-[var(--color-text)] truncate max-w-[180px]">
                {selectedAudit.finalUrl}
              </span>
            </div>
            <div className="flex justify-between items-center py-1">
              <span className="text-[var(--color-muted)]">Audit date</span>
              <span className="font-semibold text-[var(--color-text)]">
                {selectedAudit.createdAt.split('T')[0]}
              </span>
            </div>
            <div className="flex justify-between items-center py-1">
              <span className="text-[var(--color-muted)]">Crawl duration</span>
              <span className="font-semibold text-[var(--color-text)]">
                {formatDuration(selectedAudit.crawlDurationMs)}
              </span>
            </div>
            <div className="flex justify-between items-center py-1">
              <span className="text-[var(--color-muted)]">Summary</span>
              <span className="font-semibold text-[var(--color-text)]">
                Crawled 1 page · {selectedAudit.issues.length} issues identified
              </span>
            </div>
          </dl>
        </div>

        {/* Right Column: 4 Sub-score Cards (7 cols) */}
        <div className="flex flex-col justify-between gap-3 lg:col-span-8">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <ScoreCard
              category="technical"
              score={selectedAudit.technicalScore}
              onClick={() => setSelectedCategory(selectedCategory === 'technical' ? 'all' : 'technical')}
              isSelected={selectedCategory === 'technical'}
            />
            <ScoreCard
              category="content"
              score={selectedAudit.contentScore}
              onClick={() => setSelectedCategory(selectedCategory === 'content' ? 'all' : 'content')}
              isSelected={selectedCategory === 'content'}
            />
            <ScoreCard
              category="links"
              score={selectedAudit.linksScore}
              onClick={() => setSelectedCategory(selectedCategory === 'links' ? 'all' : 'links')}
              isSelected={selectedCategory === 'links'}
            />
            <ScoreCard
              category="performance"
              score={selectedAudit.performanceScore}
              onClick={() => setSelectedCategory(selectedCategory === 'performance' ? 'all' : 'performance')}
              isSelected={selectedCategory === 'performance'}
            />
          </div>

          {/* Quick Issue Stats Banner */}
          <div className="user-panel flex flex-col items-center justify-between gap-3 rounded-2xl p-4 sm:flex-row">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-xl bg-[var(--color-surface-muted)] text-[var(--color-primary)]">
                <ShieldCheck className="w-5 h-5" />
              </div>
              <div>
                <h4 className="text-xs font-bold text-[var(--color-text)]">
                  Diagnostic Summary: {selectedAudit.issues.length} Issues Detected
                </h4>
                <p className="text-xs text-[var(--color-muted)]">
                  {criticalIssuesCount > 0
                    ? `${criticalIssuesCount} critical blocker(s) require urgent remediation.`
                    : 'No critical errors detected. Address high and medium optimizations.'}
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2 shrink-0">
              <span className="text-xs font-bold px-2.5 py-1 rounded-lg bg-rose-950/80 text-rose-300 border border-rose-800">
                {criticalIssuesCount} Critical
              </span>
              <span className="text-xs font-bold px-2.5 py-1 rounded-lg bg-[var(--color-warning-bg)] text-[var(--color-warning-text)] border border-[var(--color-warning-border)]">
                {highIssuesCount} High
              </span>
            </div>
          </div>
        </div>
      </div>

      {extractedMetrics.length > 0 ? (
        <section className="user-panel rounded-2xl p-5">
          <div className="mb-5"><p className="text-[11px] font-black uppercase tracking-[0.16em] text-[var(--color-accent)]">Extracted crawl data</p><h2 className="mt-1 text-lg font-black text-[var(--color-text)]">Page signals returned by the audit engine</h2><p className="mt-1 text-xs text-[var(--color-muted)]">Backend values are rendered as plain text and optional fields are omitted when unavailable.</p></div>
          <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {extractedMetrics.map(([label, value]) => <div key={label} className="rounded-xl border border-[var(--color-border)] bg-[#050A0B]/55 p-3.5"><dt className="text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]">{label}</dt><dd className="mt-1 break-words text-sm font-bold text-[var(--color-text)]">{typeof value === 'boolean' ? value ? 'Yes' : 'No' : String(value)}</dd></div>)}
          </dl>
        </section>
      ) : null}

      <RecommendationPanel auditId={selectedAudit.id} auditStatus={selectedAudit.status} />

      {/* Issues Inspection Section */}
      <div className="user-panel space-y-5 rounded-2xl p-5 sm:p-6">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[var(--color-border)]">
          <div>
            <h3 className="text-lg font-bold text-[var(--color-text)]">Detected SEO Issues</h3>
            <p className="text-xs text-[var(--color-muted)]">
              Filter by category or severity to inspect recommendations and markup locations
            </p>
          </div>

          {/* Filter Pill Controls */}
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex max-w-full items-center gap-1 overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-1">
              {(['all', 'technical', 'content', 'links', 'performance'] as const).map((cat) => (
                <button
                  key={cat}
                  onClick={() => setSelectedCategory(cat)}
                  className={`shrink-0 px-2.5 py-1 text-xs font-bold capitalize rounded-lg transition-colors ${
                    selectedCategory === cat
                      ? 'bg-[var(--color-primary)] text-[var(--color-on-primary)] shadow-xs'
                      : 'text-[var(--color-muted)] hover:text-[var(--color-text)]'
                  }`}
                >
                  {cat}
                </button>
              ))}
            </div>

            <div className="flex max-w-full items-center gap-1 overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-1">
              {(['all', 'critical', 'high', 'medium', 'low', 'info'] as const).map((sev) => (
                <button
                  key={sev}
                  onClick={() => setSelectedSeverity(sev)}
                  className={`shrink-0 px-2.5 py-1 text-xs font-bold capitalize rounded-lg transition-colors ${
                    selectedSeverity === sev
                      ? 'bg-[var(--color-primary)] text-[var(--color-on-primary)] shadow-xs'
                      : 'text-[var(--color-muted)] hover:text-[var(--color-text)]'
                  }`}
                >
                  {sev}
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* Issues grouped by severity */}
        <div className="space-y-6">
          {filteredIssues.length > 0 ? (
            (['critical', 'high', 'medium', 'low', 'info'] as IssueSeverity[]).map((severity) => {
              const issues = filteredIssues.filter((issue) => issue.severity === severity);
              if (!issues.length) return null;

              return (
                <section key={severity} className="space-y-3">
                  <div className="flex items-center justify-between border-b border-[var(--color-border)]/60 pb-2">
                    <SeverityBadge severity={severity} size="sm" />
                    <span className="text-[11px] font-bold text-[var(--color-muted)]">
                      {issues.length} {issues.length === 1 ? 'issue' : 'issues'}
                    </span>
                  </div>
                  <div className="space-y-3">
                    {issues.map((issue) => (
                      <IssueCard
                        key={issue.id}
                        issue={issue}
                        isExpanded={expandedIssueId === issue.id}
                        onToggle={() => setExpandedIssueId(expandedIssueId === issue.id ? null : issue.id)}
                      />
                    ))}
                  </div>
                </section>
              );
            })
          ) : (
            <div className="py-12 text-center text-[var(--color-muted)] text-sm">
              No issues found matching the selected category and severity filters.
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
