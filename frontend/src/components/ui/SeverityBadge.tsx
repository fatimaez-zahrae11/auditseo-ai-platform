import React from 'react';
import { IssueSeverity } from '../../types';
import { AlertOctagon, AlertTriangle, AlertCircle, Info } from 'lucide-react';

interface SeverityBadgeProps {
  severity: IssueSeverity;
  size?: 'sm' | 'md';
}

export const SeverityBadge: React.FC<SeverityBadgeProps> = ({ severity, size = 'md' }) => {
  const sizeClasses = size === 'sm' ? 'text-xs px-2 py-0.5 gap-1' : 'text-xs px-2.5 py-1 gap-1.5 font-medium';

  switch (severity) {
    case 'critical':
      return (
        <span
          id={`severity-badge-${severity}`}
          className={`inline-flex items-center rounded-md bg-[var(--color-danger-bg)] text-[var(--color-danger-text)] border border-[var(--color-danger-border)] shadow-xs font-semibold ${sizeClasses}`}
        >
          <AlertOctagon className="w-3.5 h-3.5 shrink-0" />
          <span>Critical</span>
        </span>
      );
    case 'high':
      return (
        <span
          id={`severity-badge-${severity}`}
          className={`inline-flex items-center rounded-md bg-[var(--color-warning-bg)] text-[var(--color-warning-text)] border border-[var(--color-warning-border)] shadow-xs font-semibold ${sizeClasses}`}
        >
          <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
          <span>High Priority</span>
        </span>
      );
    case 'medium':
      return (
        <span
          id={`severity-badge-${severity}`}
          className={`inline-flex items-center rounded-md bg-[var(--color-warning-bg)] text-[var(--color-warning-text)] border border-[var(--color-warning-border)] shadow-xs font-medium ${sizeClasses}`}
        >
          <AlertCircle className="w-3.5 h-3.5 shrink-0" />
          <span>Medium</span>
        </span>
      );
    case 'low':
      return (
        <span
          id={`severity-badge-${severity}`}
          className={`inline-flex items-center rounded-md bg-[var(--color-pending-bg)] text-[var(--color-pending-text)] border border-[var(--color-border)] shadow-xs font-medium ${sizeClasses}`}
        >
          <Info className="w-3.5 h-3.5 text-[var(--color-muted)] shrink-0" />
          <span>Low</span>
        </span>
      );
    case 'info':
    default:
      return (
        <span
          id={`severity-badge-${severity}`}
          className={`inline-flex items-center rounded-md bg-[var(--color-secondary)] text-[var(--color-text)] border border-[var(--color-border)] shadow-xs font-semibold ${sizeClasses}`}
        >
          <Info className="w-3.5 h-3.5 text-[var(--color-primary)] shrink-0" />
          <span>Informational</span>
        </span>
      );
  }
};
