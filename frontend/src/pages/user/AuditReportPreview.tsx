import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowLeft, Download, Loader2 } from 'lucide-react';
import { LoadingState } from '../../components/ui/LoadingState';
import { RecommendationPanel } from '../../components/ui/RecommendationPanel';
import { RequestErrorState } from '../../components/ui/RequestErrorState';
import { ScoreCircle } from '../../components/ui/ScoreCircle';
import { SeverityBadge } from '../../components/ui/SeverityBadge';
import { AppLogo } from '../../components/ui/AppLogo';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';
import type { SeoAudit, UserAiRecommendation } from '../../types';
import { exportAuditReportPdf } from '../../utils/exportAuditReportPdf';
import { useAuditPolling } from '../../utils/useAuditPolling';
import { getPublicApiErrorMessage, PUBLIC_AUDIT_FAILURE_MESSAGE } from '../../utils/publicApiErrors';

interface PdfSafeReportProps {
  audit: SeoAudit;
  recommendations: UserAiRecommendation[];
  reportRef: React.RefObject<HTMLDivElement | null>;
}

const pdfColors = {
  ink: '#020C0F',
  green: '#C25E00',
  emerald: '#FF8A00',
  polar: '#FFF7ED',
  border: '#D8C5AF',
  muted: '#5F5145',
  white: '#FFFFFF',
};

const pdfSectionStyle: React.CSSProperties = {
  marginTop: '24px',
  paddingTop: '18px',
  borderTop: `2px solid ${pdfColors.green}`,
};

function PdfSafeReport({ audit, recommendations, reportRef }: PdfSafeReportProps) {
  const scores = [
    ['Global', audit.globalScore],
    ['Technical', audit.technicalScore],
    ['Content', audit.contentScore],
    ['Links', audit.linksScore],
    ['Performance', audit.performanceScore],
  ] as const;

  return (
    <div
      ref={reportRef}
      data-pdf-safe-report
      aria-hidden="true"
      style={{
        position: 'absolute',
        left: '-9999px',
        top: '0',
        zIndex: -1,
        width: '794px',
        margin: '0',
        padding: '32px',
        background: pdfColors.white,
        color: pdfColors.ink,
        fontFamily: 'Arial, Helvetica, sans-serif',
        fontSize: '14px',
        lineHeight: 1.5,
        pointerEvents: 'none',
      }}
    >
      <header style={{ paddingBottom: '20px', borderBottom: `3px solid ${pdfColors.green}` }}>
        <div style={{ color: pdfColors.green, fontSize: '20px', fontWeight: 800 }}>AuditSEO AI Platform</div>
        <h1 style={{ margin: '12px 0 4px', color: pdfColors.ink, fontSize: '28px', lineHeight: 1.2 }}>SEO Audit Report</h1>
        <div style={{ color: pdfColors.muted, fontSize: '12px' }}>Report ID: {audit.id}</div>
      </header>

      <section style={{ ...pdfSectionStyle, marginTop: '20px', borderTop: '0', paddingTop: '0' }}>
        <div style={{ padding: '16px', border: `1px solid ${pdfColors.border}`, background: pdfColors.polar }}>
          <div style={{ marginBottom: '8px' }}><strong>Audit URL:</strong> <span style={{ wordBreak: 'break-all' }}>{audit.requestedUrl}</span></div>
          <div style={{ marginBottom: '8px' }}><strong>Final URL:</strong> <span style={{ wordBreak: 'break-all' }}>{audit.finalUrl || audit.requestedUrl}</span></div>
          <div style={{ marginBottom: '8px' }}><strong>Audit date:</strong> {audit.createdAt.split('T')[0]}</div>
          <div><strong>Status:</strong> Completed</div>
        </div>
      </section>

      <section style={pdfSectionStyle}>
        <h2 style={{ margin: '0 0 14px', color: pdfColors.green, fontSize: '19px' }}>SEO Scores</h2>
        <div style={{ display: 'flex', gap: '10px' }}>
          {scores.map(([label, score]) => (
            <div key={label} style={{ flex: '1 1 0', padding: '14px 8px', border: `1px solid ${pdfColors.border}`, background: pdfColors.white, textAlign: 'center', breakInside: 'avoid' }}>
              <div style={{ color: pdfColors.muted, fontSize: '10px', fontWeight: 700, textTransform: 'uppercase' }}>{label}</div>
              <div style={{ marginTop: '5px', color: pdfColors.green, fontSize: '21px', fontWeight: 800 }}>{score}/100</div>
            </div>
          ))}
        </div>
      </section>

      <section style={pdfSectionStyle}>
        <h2 style={{ margin: '0 0 14px', color: pdfColors.green, fontSize: '19px' }}>Main Issues ({audit.issues.length})</h2>
        {audit.issues.length === 0 ? (
          <p style={{ margin: '0', padding: '14px', border: `1px solid ${pdfColors.border}` }}>No issues were returned for this audit.</p>
        ) : audit.issues.map((issue) => (
          <article key={issue.id} style={{ marginBottom: '12px', padding: '15px', border: `1px solid ${pdfColors.border}`, background: pdfColors.white, breakInside: 'avoid' }}>
            <div style={{ marginBottom: '7px', color: pdfColors.ink, fontSize: '15px', fontWeight: 800 }}>{issue.title}</div>
            <div style={{ marginBottom: '9px', color: pdfColors.muted, fontSize: '11px', fontWeight: 700, textTransform: 'uppercase' }}>{issue.severity} · {issue.category} SEO</div>
            <p style={{ margin: '0 0 9px', whiteSpace: 'pre-wrap', overflowWrap: 'break-word' }}>{issue.description}</p>
            <div style={{ padding: '11px', borderLeft: `4px solid ${pdfColors.emerald}`, background: pdfColors.polar, whiteSpace: 'pre-wrap', overflowWrap: 'break-word' }}><strong>Recommended fix:</strong> {issue.recommendation}</div>
          </article>
        ))}
      </section>

      <section style={pdfSectionStyle}>
        <h2 style={{ margin: '0 0 14px', color: pdfColors.green, fontSize: '19px' }}>AI Recommendations</h2>
        {recommendations.length === 0 ? (
          <p style={{ margin: '0', padding: '14px', border: `1px solid ${pdfColors.border}` }}>No AI recommendations were loaded when this report was exported.</p>
        ) : recommendations.map((recommendation, index) => (
          <article key={recommendation.id || `${recommendation.auditId}-${index}`} style={{ marginBottom: '12px', padding: '15px', border: `1px solid ${pdfColors.border}`, background: pdfColors.white, breakInside: 'avoid' }}>
            <div style={{ marginBottom: '8px', color: pdfColors.green, fontSize: '12px', fontWeight: 800 }}>Recommendation {index + 1}</div>
            <p style={{ margin: '0', whiteSpace: 'pre-wrap', overflowWrap: 'break-word' }}>{recommendation.generatedText}</p>
          </article>
        ))}
      </section>

      <footer style={{ marginTop: '28px', paddingTop: '14px', borderTop: `1px solid ${pdfColors.border}`, color: pdfColors.muted, fontSize: '11px' }}>
        Generated by AuditSEO AI Platform · Secure AI-powered SEO audit report
      </footer>
    </div>
  );
}

export const AuditReportPreview: React.FC = () => {
  const { addToast, selectedAuditId, setCurrentView } = useApp();
  const { audit, isLoading, error, retry } = useAuditPolling(selectedAuditId);
  const pdfReportRef = useRef<HTMLDivElement>(null);
  const [isExporting, setIsExporting] = useState(false);
  const [loadedRecommendations, setLoadedRecommendations] = useState<UserAiRecommendation[]>([]);
  const handleRecommendationsLoaded = useCallback((recommendations: UserAiRecommendation[]) => {
    setLoadedRecommendations(recommendations);
  }, []);

  useEffect(() => {
    if (!(error instanceof ApiError) || error.status !== 403) return;
    if (error.message === 'Account disabled') setCurrentView('account-disabled');
    else if (error.message.toLowerCase().includes('verif')) setCurrentView('email-verification');
    else setCurrentView('error-403');
  }, [error, setCurrentView]);

  if (isLoading) return <LoadingState label="Preparing your audit report..." />;
  if (error) {
    const notFound = error instanceof ApiError && error.status === 404;
    return <RequestErrorState title={notFound ? 'Audit not found' : 'Report unavailable'} message={getPublicApiErrorMessage(error, { fallback: 'The report data could not be loaded. Please return to your audit history and try again.', notFoundMessage: 'This audit does not exist or does not belong to your account.', rateLimitMessage: 'Audit status checks are temporarily rate limited. Please wait a moment and retry.' })} onRetry={retry} retryLabel="Retry" />;
  }
  if (!audit) return <RequestErrorState title="No audit selected" message="Select an audit from My Audits before opening the report export view." />;

  if (audit.status === 'pending' || audit.status === 'running') {
    return <div className="space-y-4"><div className="user-panel rounded-2xl p-4"><button disabled className="inline-flex items-center gap-2 rounded-xl bg-[var(--color-surface-muted)] px-5 py-2.5 text-xs font-bold text-[var(--color-muted)]"><Download className="h-4 w-4" />Export PDF</button><p className="mt-2 text-xs font-semibold text-[var(--color-muted)]">PDF export is available after the audit is completed.</p></div><LoadingState label={audit.status === 'pending' ? 'Audit queued—waiting for processing...' : 'Audit processing—report will open when ready...'} /><RecommendationPanel auditId={audit.id} auditStatus={audit.status} /></div>;
  }

  if (audit.status === 'failed') {
    return <div className="space-y-4"><div className="user-panel rounded-2xl p-4"><button disabled className="inline-flex items-center gap-2 rounded-xl bg-[var(--color-surface-muted)] px-5 py-2.5 text-xs font-bold text-[var(--color-muted)]"><Download className="h-4 w-4" />Export PDF</button><p className="mt-2 text-xs font-semibold text-[var(--color-muted)]">PDF export is not available for failed audits.</p></div><RequestErrorState title="Report unavailable" message={audit.failureMessage || PUBLIC_AUDIT_FAILURE_MESSAGE} /><RecommendationPanel auditId={audit.id} auditStatus={audit.status} /></div>;
  }

  const handleExport = async () => {
    if (isExporting) return;
    const reportElement = pdfReportRef.current;
    if (!reportElement) {
      addToast({ title: 'PDF Export Failed', message: 'Unable to export PDF. Please try again.', type: 'error' });
      return;
    }

    setIsExporting(true);
    try {
      await exportAuditReportPdf(reportElement, audit.id);
      addToast({ title: 'PDF Exported', message: 'PDF exported successfully.', type: 'success' });
    } catch {
      addToast({ title: 'PDF Export Failed', message: 'Unable to export PDF. Please try again.', type: 'error' });
    } finally {
      setIsExporting(false);
    }
  };

  const scoreCards = [
    ['Technical', audit.technicalScore],
    ['Content', audit.contentScore],
    ['Links', audit.linksScore],
    ['Performance', audit.performanceScore],
  ] as const;

  return (
    <div id="audit-report-preview-view" className="mx-auto max-w-5xl space-y-4">
      <div className="user-panel flex flex-col justify-between gap-4 rounded-2xl p-3.5 sm:flex-row sm:items-center">
        <div className="flex items-center gap-3"><button onClick={() => setCurrentView('audit-detail')} className="rounded-xl bg-[var(--color-surface-muted)] p-2 text-[var(--color-text)]" title="Back to Audit Detail"><ArrowLeft className="h-4 w-4" /></button><div><h2 className="text-base font-bold text-[var(--color-text)]">PDF Report</h2><p className="text-xs text-[var(--color-muted)]">Completed SEO audit for {audit.domain}</p></div></div>
        <button id="export-report-pdf-btn" onClick={() => void handleExport()} disabled={isExporting} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--color-cta)] px-5 text-xs font-bold text-[var(--color-on-cta)] hover:bg-[var(--color-cta-hover)] disabled:cursor-wait disabled:opacity-60">{isExporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}{isExporting ? 'Generating PDF...' : 'Export PDF'}</button>
      </div>

      <div className="user-panel-elevated space-y-6 rounded-2xl p-5 sm:p-8">
        <header className="flex flex-col justify-between gap-5 border-b-2 border-[var(--color-border)] pb-6 sm:flex-row sm:items-start"><div><div className="flex items-center gap-2"><AppLogo size={32} /><span className="text-lg font-extrabold text-[var(--color-text)]">AuditSEO AI Platform</span></div><h1 className="mt-4 text-2xl font-black text-[var(--color-text)]">Executive SEO Audit Report</h1><p className="mt-1 font-mono text-xs text-[var(--color-muted)]">Report ID: {audit.id}</p></div><div className="space-y-1 text-xs sm:text-right"><div className="font-bold text-[var(--color-text)]">Target: {audit.domain}</div><div className="text-[var(--color-muted)]">Audit date: {audit.createdAt.split('T')[0]}</div><div className="text-[var(--color-muted)]">Status: Completed</div></div></header>

        <section className="grid gap-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-secondary)] p-4 text-xs sm:grid-cols-2"><div><div className="text-[10px] font-bold uppercase text-[var(--color-muted)]">Audit URL</div><div className="mt-1 break-all font-mono text-[var(--color-text)]">{audit.requestedUrl}</div></div><div><div className="text-[10px] font-bold uppercase text-[var(--color-muted)]">Final URL</div><div className="mt-1 break-all font-mono text-[var(--color-text)]">{audit.finalUrl || audit.requestedUrl}</div></div></section>

        <section className="grid items-center gap-5 rounded-2xl border border-[var(--color-border)] bg-[#050A0B]/55 p-5 md:grid-cols-12"><div className="flex justify-center md:col-span-4"><ScoreCircle score={audit.globalScore} size="lg" showLabel label="Global SEO Health" /></div><div className="space-y-4 md:col-span-8"><div><h2 className="text-base font-bold text-[var(--color-text)]">SEO score breakdown</h2><p className="mt-1 text-xs leading-5 text-[var(--color-muted)]">Technical health, content quality, links, and performance signals captured by this completed audit.</p></div><div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{scoreCards.map(([label, score]) => <div key={label} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3 text-center"><div className="text-[10px] font-bold uppercase text-[var(--color-muted)]">{label}</div><div className="mt-1 text-lg font-extrabold text-[var(--color-text)]">{score}/100</div></div>)}</div></div></section>

        <RecommendationPanel auditId={audit.id} auditStatus={audit.status} allowGeneration={false} compact onRecommendationsLoaded={handleRecommendationsLoaded} />

        <section className="space-y-4"><h2 className="border-b border-[var(--color-border)] pb-2 text-base font-bold text-[var(--color-text)]">Main Issues ({audit.issues.length})</h2>{audit.issues.length === 0 ? <p className="rounded-xl border border-dashed border-[var(--color-border)] p-5 text-xs text-[var(--color-muted)]">No issues were returned for this audit.</p> : <div className="space-y-3">{audit.issues.map((issue) => <article key={issue.id} className="space-y-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 text-xs"><div className="flex flex-wrap items-center justify-between gap-2"><div className="flex items-center gap-2"><SeverityBadge severity={issue.severity} size="sm" /><span className="text-sm font-bold text-[var(--color-text)]">{issue.title}</span></div><span className="font-semibold capitalize text-[var(--color-muted)]">{issue.category} SEO</span></div><p className="whitespace-pre-wrap break-words leading-5 text-[var(--color-muted)]">{issue.description}</p><div className="rounded-lg border border-[var(--color-primary)]/30 bg-[var(--color-primary)]/15 p-3 font-medium leading-5 text-[var(--color-text)]"><strong>Recommended fix:</strong> {issue.recommendation}</div></article>)}</div>}</section>

        <footer className="flex flex-col justify-between gap-2 border-t border-[var(--color-border)] pt-6 text-xs text-[var(--color-muted)] sm:flex-row"><span>Generated by AuditSEO AI Platform</span><span>Secure AI-powered SEO audit report</span></footer>
      </div>

      <PdfSafeReport audit={audit} recommendations={loadedRecommendations} reportRef={pdfReportRef} />
    </div>
  );
};
