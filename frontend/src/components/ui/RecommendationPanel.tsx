import { useEffect, useRef, useState } from 'react';
import { AlertCircle, Bot, ChevronLeft, ChevronRight, Loader2, Sparkles } from 'lucide-react';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';
import { recommendationService } from '../../services/recommendationService';
import type { AuditStatus, RecommendationPagination, UserAiRecommendation } from '../../types';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';
import { EmptyState } from './EmptyState';

interface RecommendationPanelProps {
  auditId: string;
  auditStatus: AuditStatus;
  allowGeneration?: boolean;
  compact?: boolean;
  onRecommendationsLoaded?: (recommendations: UserAiRecommendation[]) => void;
}

const emptyPagination: RecommendationPagination = {
  currentPage: 1,
  lastPage: 1,
  perPage: 20,
  total: 0,
  from: null,
  to: null,
  previousPageUrl: null,
  nextPageUrl: null,
};

const friendlyError = (error: unknown, action: 'load' | 'generate') => {
  if (!(error instanceof ApiError)) return action === 'load'
    ? 'Recommendations could not be loaded. Please try again.'
    : 'Recommendations could not be generated. Please try again.';
  if (error.status === 403) return 'Access denied. You do not have permission to access recommendations for this audit.';
  if (error.status === 404) return 'Audit not found.';
  if (error.status === 409) return 'The audit must be completed before generating recommendations.';
  if (error.status === 422) return getPublicApiErrorMessage(error, {
    validationFallback: action === 'load'
      ? 'The recommendation request was rejected.'
      : 'Check the recommendation request and try again.',
  });
  if (error.status === 429) return action === 'load'
    ? 'Recommendation history is temporarily rate limited. Please wait a moment and refresh.'
    : 'Recommendation generation is temporarily rate limited. Please wait a moment before trying again.';
  if (error.status === 502) return 'The AI recommendation provider is temporarily unavailable. Please try again later.';
  if (error.status === 0) return 'The recommendation service could not be reached. Check your connection and try again.';
  return action === 'load'
    ? 'Recommendations could not be loaded. Please try again.'
    : 'Recommendations could not be generated. Please try again.';
};

export function RecommendationPanel({
  auditId,
  auditStatus,
  allowGeneration = true,
  compact = false,
  onRecommendationsLoaded,
}: RecommendationPanelProps) {
  const { addToast, setCurrentView } = useApp();
  const [recommendations, setRecommendations] = useState<UserAiRecommendation[]>([]);
  const [pagination, setPagination] = useState<RecommendationPagination>(emptyPagination);
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const generationInFlight = useRef(false);

  useEffect(() => {
    let isActive = true;
    setIsLoading(true);
    setErrorMessage('');

    recommendationService.list(auditId, page)
      .then((response) => {
        if (!isActive) return;
        setRecommendations(response.recommendations);
        setPagination(response.pagination);
        onRecommendationsLoaded?.(response.recommendations);
      })
      .catch((error: unknown) => {
        if (!isActive) return;
        if (error instanceof ApiError && error.status === 403 && error.message === 'Account disabled') {
          setCurrentView('account-disabled');
          return;
        }
        if (error instanceof ApiError && error.status === 403 && error.message.toLowerCase().includes('verif')) {
          setCurrentView('email-verification');
          return;
        }
        setErrorMessage(friendlyError(error, 'load'));
      })
      .finally(() => {
        if (isActive) setIsLoading(false);
      });

    return () => { isActive = false; };
  }, [auditId, page, refreshKey, setCurrentView, onRecommendationsLoaded]);

  const generateRecommendation = async () => {
    if (auditStatus !== 'completed' || generationInFlight.current) return;
    generationInFlight.current = true;
    setIsGenerating(true);
    setErrorMessage('');
    try {
      await recommendationService.generate(auditId);
      addToast({
        title: 'Recommendations Ready',
        message: 'AI recommendations generated successfully.',
        type: 'success',
      });
      setPage(1);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      if (error instanceof ApiError && error.status === 403 && error.message === 'Account disabled') {
        setCurrentView('account-disabled');
      } else if (error instanceof ApiError && error.status === 403 && error.message.toLowerCase().includes('verif')) {
        setCurrentView('email-verification');
      } else {
        setErrorMessage(friendlyError(error, 'generate'));
      }
    } finally {
      generationInFlight.current = false;
      setIsGenerating(false);
    }
  };

  const availabilityMessage = auditStatus === 'failed'
    ? 'Recommendations cannot be generated for a failed audit.'
    : auditStatus === 'pending' || auditStatus === 'running'
      ? 'Recommendations are available after the audit is completed.'
      : null;

  return (
    <section className={`user-panel-elevated rounded-2xl ${compact ? 'p-4 sm:p-5' : 'p-5 sm:p-6'}`}>
      <div className="flex flex-col justify-between gap-4 border-b border-[var(--color-border)]/80 pb-4 md:flex-row md:items-center">
        <div>
          <div className="inline-flex items-center gap-2 rounded-full border border-[var(--color-primary)]/40 bg-[var(--color-canvas)]/80 px-3 py-1 text-xs font-bold text-[var(--color-muted)]"><Bot className="h-3.5 w-3.5 text-[var(--color-primary)]" />AI Recommendations</div>
          <h3 className="mt-2 text-lg font-black text-[var(--color-text)]">Prioritized SEO guidance</h3>
          <p className="mt-1 text-xs text-[var(--color-muted)]">Stored recommendation history for this completed audit.</p>
        </div>

        {allowGeneration ? <div className="md:text-right"><button type="button" onClick={() => void generateRecommendation()} disabled={auditStatus !== 'completed' || isLoading || isGenerating} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 text-xs font-black text-[var(--color-on-primary)] transition-colors hover:bg-[var(--color-primary-hover)] disabled:cursor-not-allowed disabled:bg-[var(--color-surface-muted)] disabled:text-[var(--color-muted)]">{isGenerating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}{isGenerating ? 'Generating recommendations...' : recommendations.length ? 'Generate New Recommendations' : 'Generate AI Recommendations'}</button>{availabilityMessage ? <p className="mt-2 text-[11px] font-semibold text-[var(--color-muted)]">{availabilityMessage}</p> : null}</div> : null}
      </div>

      {errorMessage ? <div className="mt-5 flex items-start gap-2.5 rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-4 text-xs font-semibold leading-5 text-[var(--color-danger-text)]" role="alert"><AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />{errorMessage}</div> : null}

      <div className="mt-5">
        {isLoading ? <div className="flex min-h-32 items-center justify-center gap-2 text-xs font-bold text-[var(--color-muted)]" role="status"><Loader2 className="h-4 w-4 animate-spin text-[var(--color-primary)]" />Loading recommendation history...</div> : recommendations.length === 0 ? <EmptyState title="No AI recommendations yet" description={auditStatus === 'completed' ? 'Generate the first prioritized recommendation for this audit.' : availabilityMessage || 'No recommendations are available.'} compact /> : <div className="space-y-3">{recommendations.map((recommendation, index) => <article key={recommendation.id || `${recommendation.auditId}-${index}`} className="rounded-xl border border-[var(--color-border)] bg-[#050A0B]/65 p-4"><div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]"><span>Recommendation {pagination.total - ((pagination.currentPage - 1) * pagination.perPage) - index}</span><time dateTime={recommendation.createdAt}>{recommendation.createdAt.split('T')[0]}</time></div><p className="whitespace-pre-wrap break-words text-xs leading-6 text-[var(--color-text)] sm:text-sm">{recommendation.generatedText}</p></article>)}</div>}
      </div>

      {!isLoading && pagination.lastPage > 1 ? <div data-pdf-exclude className="mt-5 flex items-center justify-end gap-2 text-xs text-[var(--color-muted)]"><button type="button" disabled={!pagination.previousPageUrl} onClick={() => setPage((current) => Math.max(1, current - 1))} className="rounded-lg border border-[var(--color-border)] p-2 disabled:opacity-35" aria-label="Previous recommendations page"><ChevronLeft className="h-4 w-4" /></button><span>Page {pagination.currentPage} of {pagination.lastPage}</span><button type="button" disabled={!pagination.nextPageUrl} onClick={() => setPage((current) => current + 1)} className="rounded-lg border border-[var(--color-border)] p-2 disabled:opacity-35" aria-label="Next recommendations page"><ChevronRight className="h-4 w-4" /></button></div> : null}
    </section>
  );
}
