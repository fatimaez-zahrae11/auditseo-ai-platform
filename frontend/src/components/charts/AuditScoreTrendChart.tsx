import React from 'react';
import type { SeoAudit } from '../../types';

interface AuditScoreTrendChartProps {
  audits: SeoAudit[];
}

export const AuditScoreTrendChart: React.FC<AuditScoreTrendChartProps> = ({ audits }) => {
  const completed = audits
    .filter((audit) => audit.status === 'completed')
    .slice(0, 8)
    .reverse();

  if (completed.length === 0) {
    return (
      <div className="flex min-h-56 items-center justify-center rounded-2xl border border-dashed border-[var(--color-border)] bg-[var(--color-canvas)]/50 px-6 text-center">
        <div><p className="text-sm font-bold text-[var(--color-text)]">No completed score history yet</p><p className="mt-1 text-xs text-[var(--color-muted)]">Completed audits will appear here automatically.</p></div>
      </div>
    );
  }

  const width = 640;
  const height = 220;
  const paddingX = 34;
  const paddingY = 24;
  const plotWidth = width - paddingX * 2;
  const plotHeight = height - paddingY * 2;
  const points = completed.map((audit, index) => {
    const x = completed.length === 1 ? width / 2 : paddingX + (index / (completed.length - 1)) * plotWidth;
    const y = paddingY + ((100 - Math.max(0, Math.min(100, audit.globalScore))) / 100) * plotHeight;
    return { audit, x, y };
  });

  return (
    <div className="w-full">
      <div className="mb-5"><h3 className="text-sm font-bold text-[var(--color-text)]">SEO score trend</h3><p className="mt-1 text-xs text-[var(--color-muted)]">Your latest completed audits, oldest to newest</p></div>
      <div className="overflow-x-auto">
        <svg viewBox={`0 0 ${width} ${height}`} className="min-w-[540px]" role="img" aria-label="Global SEO score trend for recent completed audits">
          {[0, 25, 50, 75, 100].map((score) => {
            const y = paddingY + ((100 - score) / 100) * plotHeight;
            return <g key={score}><line x1={paddingX} x2={width - paddingX} y1={y} y2={y} stroke="var(--color-border)" strokeDasharray="4 6" /><text x={4} y={y + 4} fill="var(--color-muted)" fontSize="10">{score}</text></g>;
          })}
          {points.length > 1 ? <polyline points={points.map(({ x, y }) => `${x},${y}`).join(' ')} fill="none" stroke="var(--color-primary)" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" /> : null}
          {points.map(({ audit, x, y }) => <g key={audit.id}><circle cx={x} cy={y} r="6" fill="var(--color-primary)" stroke="var(--color-surface)" strokeWidth="3"><title>{audit.domain}: {audit.globalScore}/100</title></circle><text x={x} y={height - 4} textAnchor="middle" fill="var(--color-muted)" fontSize="9">{audit.createdAt.split('T')[0].slice(5)}</text></g>)}
        </svg>
      </div>
    </div>
  );
};
