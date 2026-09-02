import React from 'react';
import { AuditStatus, UserStatus, EmailVerificationStatus } from '../../types';
import { CheckCircle2, Clock, AlertCircle, Loader2, UserCheck, UserX, ShieldAlert } from 'lucide-react';

interface StatusBadgeProps {
  status: AuditStatus | UserStatus | EmailVerificationStatus;
  size?: 'sm' | 'md' | 'lg';
}

export const StatusBadge: React.FC<StatusBadgeProps> = ({ status, size = 'md' }) => {
  const sizeClasses = {
    sm: 'text-xs px-2 py-0.5 gap-1',
    md: 'text-xs px-2.5 py-1 gap-1.5 font-medium',
    lg: 'text-sm px-3 py-1.5 gap-2 font-medium',
  }[size];

  switch (status) {
    case 'completed':
      return (
        <span
          id={`status-completed-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-completed-bg)] text-[var(--color-completed-text)] border border-[var(--color-border)] shadow-xs font-semibold ${sizeClasses}`}
        >
          <CheckCircle2 className="w-3.5 h-3.5 text-[var(--color-completed-text)] shrink-0" />
          <span>Completed</span>
        </span>
      );
    case 'running':
      return (
        <span
          id={`status-running-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-running-bg)] text-[var(--color-running-text)] border border-[var(--color-border)] shadow-xs font-semibold ${sizeClasses}`}
        >
          <Loader2 className="w-3.5 h-3.5 text-[var(--color-running-text)] animate-spin shrink-0" />
          <span>Crawling & Analyzing</span>
        </span>
      );
    case 'pending':
      return (
        <span
          id={`status-pending-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-pending-bg)] text-[var(--color-pending-text)] border border-[var(--color-border)] shadow-xs ${sizeClasses}`}
        >
          <Clock className="w-3.5 h-3.5 text-[var(--color-pending-text)] shrink-0" />
          <span>Queued</span>
        </span>
      );
    case 'failed':
      return (
        <span
          id={`status-failed-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-danger-bg)] text-[var(--color-danger-text)] border border-[var(--color-danger-border)] shadow-xs ${sizeClasses}`}
        >
          <AlertCircle className="w-3.5 h-3.5 shrink-0" />
          <span>Failed</span>
        </span>
      );
    case 'active':
      return (
        <span
          id={`status-user-active-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-success-bg)] text-[var(--color-success-text)] border border-[var(--color-success-border)] font-semibold ${sizeClasses}`}
        >
          <UserCheck className="w-3.5 h-3.5 shrink-0" />
          <span>Active</span>
        </span>
      );
    case 'inactive':
      return (
        <span
          id={`status-user-inactive-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-surface-muted)]/40 text-[var(--color-muted)] border border-[var(--color-border)] ${sizeClasses}`}
        >
          <UserX className="w-3.5 h-3.5 text-[var(--color-muted)] shrink-0" />
          <span>Inactive</span>
        </span>
      );
    case 'suspended':
      return (
        <span
          id={`status-user-suspended-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-danger-bg)] text-[var(--color-danger-text)] border border-[var(--color-danger-border)] ${sizeClasses}`}
        >
          <ShieldAlert className="w-3.5 h-3.5 shrink-0" />
          <span>Suspended</span>
        </span>
      );
    case 'verified':
      return (
        <span
          id={`status-email-verified-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-success-bg)] text-[var(--color-success-text)] border border-[var(--color-success-border)] font-semibold ${sizeClasses}`}
        >
          <CheckCircle2 className="w-3 h-3 shrink-0" />
          <span>Verified</span>
        </span>
      );
    case 'unverified':
      return (
        <span
          id={`status-email-unverified-${status}`}
          className={`inline-flex items-center rounded-full bg-[var(--color-warning-bg)] text-[var(--color-warning-text)] border border-[var(--color-warning-border)] ${sizeClasses}`}
        >
          <Clock className="w-3 h-3 shrink-0" />
          <span>Unverified</span>
        </span>
      );
    default:
      return null;
  }
};
