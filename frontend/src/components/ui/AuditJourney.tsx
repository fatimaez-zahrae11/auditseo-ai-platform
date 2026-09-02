import {
  Bot,
  Check,
  Circle,
  FileCheck2,
  FileText,
  Loader2,
  Radar,
  SearchCheck,
} from 'lucide-react';
import type { AuditStatus } from '../../types';

type JourneyState = 'completed' | 'running' | 'pending';

interface AuditJourneyProps {
  auditStatus?: AuditStatus;
  hasRecommendation?: boolean;
}

const journeySteps = [
  { label: 'URL submitted', icon: Radar },
  { label: 'Crawler started', icon: SearchCheck },
  { label: 'Technical checks completed', icon: FileCheck2 },
  { label: 'SEO issues detected', icon: Circle },
  { label: 'AI recommendations generated', icon: Bot },
  { label: 'PDF report ready', icon: FileText },
];

function resolveStepState(
  index: number,
  auditStatus?: AuditStatus,
  hasRecommendation?: boolean,
): JourneyState {
  if (!auditStatus) return 'pending';
  if (index === 0) return 'completed';

  if (auditStatus === 'pending') return index === 1 ? 'running' : 'pending';
  if (auditStatus === 'running') return index <= 1 ? 'completed' : index === 2 ? 'running' : 'pending';
  if (auditStatus === 'failed') return index === 1 ? 'completed' : 'pending';

  if (index <= 3) return 'completed';
  if (index === 4) return hasRecommendation ? 'completed' : 'running';
  return hasRecommendation ? 'completed' : 'pending';
}

export function AuditJourney({ auditStatus, hasRecommendation = false }: AuditJourneyProps) {
  return (
    <section className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg sm:p-6">
      <div className="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-[11px] font-extrabold uppercase tracking-[0.18em] text-[var(--color-accent)]">
            Guided workflow
          </p>
          <h2 className="mt-1 text-lg font-black tracking-tight text-[var(--color-text)]">Audit Journey</h2>
          <p className="mt-1 text-xs text-[var(--color-muted)]">
            Follow every stage from secure crawl intake to an export-ready action plan.
          </p>
        </div>
        <span className="w-fit rounded-full border border-[var(--color-border)] bg-[var(--color-secondary)] px-3 py-1 text-[11px] font-bold text-[var(--color-muted)]">
          Live audit status
        </span>
      </div>

      <ol className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        {journeySteps.map((step, index) => {
          const state = resolveStepState(index, auditStatus, hasRecommendation);
          const Icon = step.icon;

          return (
            <li key={step.label} className="relative min-w-0">
              {index < journeySteps.length - 1 ? (
                <div className="absolute left-9 top-5 hidden h-px w-[calc(100%-1rem)] bg-[var(--color-border)] xl:block" />
              ) : null}
              <div
                className={`relative h-full rounded-2xl border p-3 transition-colors ${
                  state === 'completed'
                    ? 'border-[var(--color-primary)]/40 bg-[var(--color-primary)]/10'
                    : state === 'running'
                      ? 'border-[var(--color-accent)] bg-[var(--color-highlight)]/25'
                      : 'border-[var(--color-border)] bg-[var(--color-secondary)]/70'
                }`}
              >
                <div className="flex items-center justify-between gap-2">
                  <span
                    className={`flex h-9 w-9 items-center justify-center rounded-xl border ${
                      state === 'completed'
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-[var(--color-on-primary)]'
                        : state === 'running'
                          ? 'border-[var(--color-accent)] bg-[var(--color-highlight)] text-[var(--color-on-highlight)]'
                          : 'border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-muted)]'
                    }`}
                  >
                    {state === 'completed' ? (
                      <Check className="h-4 w-4" />
                    ) : state === 'running' ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Icon className="h-4 w-4" />
                    )}
                  </span>
                  <span className="text-[10px] font-black uppercase tracking-wider text-[var(--color-muted)]">
                    {state}
                  </span>
                </div>
                <p className="mt-3 text-xs font-bold leading-5 text-[var(--color-text)]">{step.label}</p>
              </div>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
