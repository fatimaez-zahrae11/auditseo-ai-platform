import React from 'react';
import { useApp } from '../../context/AppContext';
import {
  ShieldAlert,
  Lock,
  FileQuestion,
  AlertTriangle,
  Flame,
  ServerCrash,
  Radio,
  Timer,
  ArrowLeft,
  RefreshCw,
  LogIn,
  Home,
  UserX,
} from 'lucide-react';
import { ViewType } from '../../types';

interface ErrorStateProps {
  errorType: ViewType;
}

export const ErrorState: React.FC<ErrorStateProps> = ({ errorType }) => {
  const { setCurrentView, logout } = useApp();

  const getErrorConfig = () => {
    switch (errorType) {
      case 'error-401':
        return {
          code: '401',
          title: 'Authentication Required',
          badge: 'Unauthorized Session',
          desc: 'Your active authorization bearer token has expired or is invalid. All secure session state has been purged for security compliance.',
          icon: Lock,
          accent: 'text-[var(--color-warning-text)] bg-[var(--color-warning-bg)] border-[var(--color-warning-border)]',
          primaryAction: {
            label: 'Sign In Again',
            icon: LogIn,
            onClick: () => setCurrentView('login'),
          },
          secondaryAction: {
            label: 'Go to Homepage',
            icon: Home,
            onClick: () => setCurrentView('login'),
          },
        };
      case 'error-403':
        return {
          code: '403',
          title: 'Access Denied',
          badge: 'Insufficient Privileges (Admin Only)',
          desc: 'You do not have administrative clearance to access this control center view. Regular accounts are restricted to user-level audits and personal analytics.',
          icon: ShieldAlert,
          accent: 'text-[var(--color-danger-text)] bg-[var(--color-danger-bg)] border-[var(--color-danger-border)]',
          primaryAction: {
            label: 'Return to User Dashboard',
            icon: ArrowLeft,
            onClick: () => setCurrentView('user-dashboard'),
          },
          secondaryAction: {
            label: 'View My Audits',
            icon: ArrowLeft,
            onClick: () => setCurrentView('my-audits'),
          },
        };
      case 'error-404':
        return {
          code: '404',
          title: 'Resource Not Found',
          badge: 'Target Missing',
          desc: 'The requested SEO audit report, user record, or recommendation snapshot could not be located in the current workspace cluster.',
          icon: FileQuestion,
          accent: 'text-[var(--color-muted)] bg-[var(--color-secondary)] border-[var(--color-border)]',
          primaryAction: {
            label: 'Return to Dashboard',
            icon: Home,
            onClick: () => setCurrentView('user-dashboard'),
          },
          secondaryAction: {
            label: 'View All My Audits',
            icon: ArrowLeft,
            onClick: () => setCurrentView('my-audits'),
          },
        };
      case 'error-409':
        return {
          code: '409',
          title: 'Conflict Detected',
          badge: 'Audit Not Ready',
          desc: 'AI Recommendations are available only after the selected SEO audit has completed. Keep the audit in view while processing finishes.',
          icon: AlertTriangle,
          accent: 'text-[var(--color-warning-text)] bg-[var(--color-warning-bg)] border-[var(--color-warning-border)]',
          primaryAction: {
            label: 'View Audit Progress',
            icon: ArrowLeft,
            onClick: () => setCurrentView('my-audits'),
          },
          secondaryAction: {
            label: 'Return to Dashboard',
            icon: Home,
            onClick: () => setCurrentView('user-dashboard'),
          },
        };
      case 'error-422':
        return {
          code: '422',
          title: 'Unprocessable Entity',
          badge: 'Validation & Policy Rejection',
          desc: 'The submitted URL failed schema validation, is unresolvable via public DNS, or targets an internal RFC 1918 private subnet (SSRF protection enforced).',
          icon: Flame,
          accent: 'text-[var(--color-warning-text)] bg-[var(--color-warning-bg)] border-[var(--color-warning-border)]',
          primaryAction: {
            label: 'Submit Valid URL',
            icon: RefreshCw,
            onClick: () => setCurrentView('create-audit'),
          },
          secondaryAction: {
            label: 'Go to Dashboard',
            icon: Home,
            onClick: () => setCurrentView('user-dashboard'),
          },
        };
      case 'error-429':
        return {
          code: '429',
          title: 'Rate Limit Exceeded',
          badge: '429 Too Many Requests',
          desc: 'The request limit for this operation has been reached. Wait for the retry window before submitting another request.',
          icon: Timer,
          accent: 'text-[var(--color-muted)] bg-[var(--color-secondary)] border-[var(--color-border)]',
          primaryAction: {
            label: 'View Existing Audits',
            icon: ArrowLeft,
            onClick: () => setCurrentView('my-audits'),
          },
          secondaryAction: {
            label: 'System Health Status',
            icon: Radio,
            onClick: () => setCurrentView('system-health'),
          },
        };
      case 'error-500':
        return {
          code: '500',
          title: 'Internal Server Error',
          badge: 'Unexpected Platform Error',
          desc: 'The request could not be completed because of an unexpected server-side error. Sensitive diagnostic details are intentionally hidden.',
          icon: ServerCrash,
          accent: 'text-[var(--color-danger-text)] bg-[var(--color-danger-bg)] border-[var(--color-danger-border)]',
          primaryAction: {
            label: 'Retry Audit Analysis',
            icon: RefreshCw,
            onClick: () => setCurrentView('create-audit'),
          },
          secondaryAction: {
            label: 'Dashboard',
            icon: Home,
            onClick: () => setCurrentView('user-dashboard'),
          },
        };
      case 'error-502':
        return {
          code: '502',
          title: 'Bad Gateway',
          badge: 'AI Service Unavailable',
          desc: 'The AI Recommendations provider is temporarily unavailable. Existing audit results remain safe and can still be reviewed.',
          icon: Radio,
          accent: 'text-[var(--color-danger-text)] bg-[var(--color-danger-bg)] border-[var(--color-danger-border)]',
          primaryAction: {
            label: 'Try Again',
            icon: RefreshCw,
            onClick: () => setCurrentView('create-audit'),
          },
          secondaryAction: {
            label: 'Return to Dashboard',
            icon: Home,
            onClick: () => setCurrentView('user-dashboard'),
          },
        };
      case 'error-503':
        return {
          code: '503',
          title: 'Service Unavailable',
          badge: 'Service Dependency Unavailable',
          desc: 'The AuditSEO AI Platform audit service or one of its required dependencies is temporarily unavailable. Try again after the service recovers.',
          icon: RefreshCw,
          accent: 'text-[var(--color-warning-text)] bg-[var(--color-warning-bg)] border-[var(--color-warning-border)]',
          primaryAction: {
            label: 'Refresh Status',
            icon: RefreshCw,
            onClick: () => setCurrentView('user-dashboard'),
          },
          secondaryAction: {
            label: 'View System Logs',
            icon: ShieldAlert,
            onClick: () => setCurrentView('system-logs'),
          },
        };
      case 'account-disabled':
      default:
        return {
          code: '403-DIS',
          title: 'Account Disabled',
          badge: 'Administrative Lock',
          desc: 'This user account has been disabled or marked as inactive by a system administrator. Please contact your organization administrator to restore access.',
          icon: UserX,
          accent: 'text-[var(--color-muted)] bg-[var(--color-secondary)] border-[var(--color-border)]',
          primaryAction: {
            label: 'Sign In With Another Account',
            icon: LogIn,
            onClick: () => logout(),
          },
          secondaryAction: {
            label: 'Return to Sign In',
            icon: ArrowLeft,
            onClick: () => setCurrentView('login'),
          },
        };
    }
  };

  const config = getErrorConfig();
  const Icon = config.icon;
  const PrimaryIcon = config.primaryAction.icon;
  const SecondaryIcon = config.secondaryAction.icon;

  return (
    <div
      id={`error-state-${errorType}`}
      className="min-h-[75vh] flex items-center justify-center p-6 bg-[var(--color-canvas)]"
    >
      <div className="max-w-xl w-full text-center bg-[var(--color-surface)] rounded-3xl p-8 sm:p-10 border border-[var(--color-border)] shadow-2xl">
        <div className="inline-flex items-center justify-center p-4 rounded-2xl border mb-6 shadow-xs mx-auto animate-bounce duration-1000">
          <div className={`p-3 rounded-xl border ${config.accent}`}>
            <Icon className="w-8 h-8" />
          </div>
        </div>

        <div className="inline-block px-3 py-1 rounded-full text-xs font-bold tracking-wider uppercase mb-3 bg-[var(--color-surface-muted)]/60 text-[var(--color-muted)] border border-[var(--color-border)]">
          HTTP {config.code} • {config.badge}
        </div>

        <h1 className="text-2xl sm:text-3xl font-extrabold text-[var(--color-text)] tracking-tight mb-3">
          {config.title}
        </h1>

        <p className="text-sm sm:text-base text-[var(--color-muted)] leading-relaxed mb-8 max-w-md mx-auto">
          {config.desc}
        </p>

        <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
          <button
            id="error-primary-action-btn"
            onClick={config.primaryAction.onClick}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl font-bold text-sm bg-[var(--color-primary)] text-[var(--color-on-primary)] hover:bg-[var(--color-primary-hover)] transition-colors shadow-md shadow-[var(--color-primary)]/20"
          >
            <PrimaryIcon className="w-4 h-4" />
            <span>{config.primaryAction.label}</span>
          </button>

          <button
            id="error-secondary-action-btn"
            onClick={config.secondaryAction.onClick}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl font-semibold text-sm bg-[var(--color-surface-muted)] text-[var(--color-text)] hover:bg-[var(--color-surface-muted)]/80 border border-[var(--color-primary)]/30 transition-colors"
          >
            <SecondaryIcon className="w-4 h-4" />
            <span>{config.secondaryAction.label}</span>
          </button>
        </div>

        <div className="mt-8 flex items-center border-t border-[var(--color-border)] pt-6 text-xs text-[var(--color-muted)]/70">
          <span>AuditSEO AI Platform Security Gateway</span>
        </div>
      </div>
    </div>
  );
};
