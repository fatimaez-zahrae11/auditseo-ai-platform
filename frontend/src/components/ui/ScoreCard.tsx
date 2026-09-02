import React from 'react';
import { Cpu, FileText, Link2, Zap } from 'lucide-react';
import { IssueCategory } from '../../types';

interface ScoreCardProps {
  category: IssueCategory;
  score: number;
  subtext?: string;
  onClick?: () => void;
  isSelected?: boolean;
}

export const ScoreCard: React.FC<ScoreCardProps> = ({
  category,
  score,
  subtext,
  onClick,
  isSelected,
}) => {
  const getCategoryConfig = (cat: IssueCategory) => {
    switch (cat) {
      case 'technical':
        return {
          title: 'Technical SEO',
          icon: Cpu,
          desc: 'Crawlability, canonicals, robots.txt & schema',
          accent: 'text-[var(--color-primary)] bg-[var(--color-primary)]/15 border-[var(--color-primary)]/30',
          barColor: 'bg-[var(--color-primary)]',
        };
      case 'content':
        return {
          title: 'Content & Meta',
          icon: FileText,
          desc: 'Headings, meta tags, alt texts & keywords',
          accent: 'text-[var(--color-muted)] bg-[var(--color-soft)]/15 border-[var(--color-soft)]/30',
          barColor: 'bg-[var(--color-soft)]',
        };
      case 'links':
        return {
          title: 'Link Health',
          icon: Link2,
          desc: 'Internal structure, anchors & 404 links',
          accent: 'text-[var(--color-primary)] bg-[var(--color-surface-strong)]/20 border-[var(--color-border)]/35',
          barColor: 'bg-[var(--color-surface-strong)]',
        };
      case 'performance':
        return {
          title: 'Performance & Signals',
          icon: Zap,
          desc: 'Response time, page size, compression & cache headers',
          accent: 'text-[var(--color-primary)] bg-[var(--color-primary)]/15 border-[var(--color-primary)]/30',
          barColor: 'bg-[var(--color-primary)]',
        };
    }
  };

  const config = getCategoryConfig(category);
  const Icon = config.icon;

  const getScoreColor = (val: number) => {
    if (val >= 85) return 'text-[var(--color-primary)] bg-[var(--color-primary)]/20 border-[var(--color-primary)]/40';
    if (val >= 70) return 'text-[var(--color-muted)] bg-[var(--color-surface-muted)]/60 border-[var(--color-border)]';
    if (val >= 50) return 'text-[var(--color-warning-text)] bg-[var(--color-warning-bg)] border-[var(--color-warning-border)]';
    return 'text-[var(--color-danger-text)] bg-[var(--color-danger-bg)] border-[var(--color-danger-border)]';
  };

  return (
    <div
      id={`score-card-${category}`}
      onClick={onClick}
      className={`relative rounded-2xl border bg-[var(--color-surface)] p-4 transition-all duration-200 ${
        isSelected
          ? 'border-[var(--color-primary)]/70 ring-2 ring-[var(--color-primary)]/15 shadow-[0_12px_28px_rgba(255,138,0,0.1)]'
          : 'border-[var(--color-border)] hover:border-[var(--color-primary)]/45'
      } ${onClick ? 'cursor-pointer' : ''}`}
    >
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-3">
          <div className={`rounded-xl border p-2 ${config.accent}`}>
            <Icon className="h-4 w-4" />
          </div>
          <div>
            <h4 className="text-sm font-bold text-[var(--color-text)]">{config.title}</h4>
            <p className="text-xs text-[var(--color-muted)] line-clamp-1">{subtext || config.desc}</p>
          </div>
        </div>

        <span
          className={`px-2.5 py-1 text-sm font-bold rounded-lg border ${getScoreColor(
            score
          )}`}
        >
          {score}/100
        </span>
      </div>

      <div className="mt-3">
        <div className="flex justify-between items-center text-xs mb-1">
          <span className="text-[var(--color-muted)] font-medium">Optimization Level</span>
          <span className="font-semibold text-[var(--color-text)]">
            {score >= 85 ? 'Optimized' : score >= 70 ? 'Good' : score >= 50 ? 'Needs Attention' : 'Critical'}
          </span>
        </div>
        <div className="w-full bg-[var(--color-canvas)] rounded-full h-2 overflow-hidden border border-[var(--color-border)]/60">
          <div
            className={`h-full rounded-full transition-all duration-700 ease-out ${config.barColor}`}
            style={{ width: `${Math.min(100, Math.max(0, score))}%` }}
          />
        </div>
      </div>
    </div>
  );
};
