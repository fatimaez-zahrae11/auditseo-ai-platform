import React from 'react';
import { useApp } from '../../context/AppContext';
import { CheckCircle2, AlertCircle, Info, AlertTriangle, X } from 'lucide-react';

export const ToastContainer: React.FC = () => {
  const { toasts, removeToast } = useApp();

  if (toasts.length === 0) return null;

  return (
    <div
      id="toast-notifications-container"
      className="fixed bottom-5 right-5 z-50 flex flex-col gap-2.5 max-w-md w-full pointer-events-none"
    >
      {toasts.map((toast) => {
        const getIcon = () => {
          switch (toast.type) {
            case 'success':
              return <CheckCircle2 className="w-5 h-5 text-[var(--color-success-text)] shrink-0" />;
            case 'error':
              return <AlertCircle className="w-5 h-5 text-rose-600 shrink-0" />;
            case 'warning':
              return <AlertTriangle className="w-5 h-5 text-[var(--color-warning-text)] shrink-0" />;
            case 'info':
            default:
              return <Info className="w-5 h-5 text-[var(--color-primary)] shrink-0" />;
          }
        };

        const getBorderColor = () => {
          switch (toast.type) {
            case 'success':
              return 'border-[var(--color-success-border)] bg-[var(--color-surface)] text-[var(--color-text)]';
            case 'error':
              return 'border-rose-500/40 bg-[var(--color-surface)] text-[var(--color-text)]';
            case 'warning':
              return 'border-[var(--color-warning-border)] bg-[var(--color-surface)] text-[var(--color-text)]';
            case 'info':
            default:
              return 'border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)]';
          }
        };

        return (
          <div
            key={toast.id}
            id={`toast-${toast.id}`}
            className={`pointer-events-auto flex items-start gap-3 p-4 rounded-2xl border shadow-xl transition-all animate-in slide-in-from-bottom-3 duration-200 ${getBorderColor()}`}
          >
            {getIcon()}
            <div className="flex-1 min-w-0">
              <h5 className="text-sm font-bold text-[var(--color-text)]">{toast.title}</h5>
              {toast.message && (
                <p className="text-xs text-[var(--color-muted)] mt-0.5 leading-relaxed break-words">
                  {toast.message}
                </p>
              )}
            </div>
            <button
              onClick={() => removeToast(toast.id)}
              className="text-[var(--color-muted)] hover:text-[var(--color-text)] p-1 -mr-1 rounded-lg transition-colors"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        );
      })}
    </div>
  );
};
