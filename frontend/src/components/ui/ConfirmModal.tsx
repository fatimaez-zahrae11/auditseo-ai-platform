import React from 'react';
import { Modal } from './Modal';
import { AlertTriangle, AlertOctagon, CheckCircle2 } from 'lucide-react';

interface ConfirmModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void | Promise<void>;
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: 'danger' | 'warning' | 'primary';
  isLoading?: boolean;
}

export const ConfirmModal: React.FC<ConfirmModalProps> = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  variant = 'danger',
  isLoading = false,
}) => {
  const getVariantStyles = () => {
    switch (variant) {
      case 'danger':
        return {
          icon: AlertOctagon,
          iconBg: 'bg-[var(--color-danger-bg)] text-[var(--color-danger-text)]',
          btnBg: 'bg-[var(--color-danger-text)] hover:opacity-90 text-[var(--color-on-semantic)] focus:ring-[var(--color-danger-border)]',
        };
      case 'warning':
        return {
          icon: AlertTriangle,
          iconBg: 'bg-[var(--color-warning-bg)] text-[var(--color-warning-text)]',
          btnBg: 'bg-[var(--color-warning-text)] hover:opacity-90 text-[var(--color-on-semantic)] focus:ring-[var(--color-warning-border)]',
        };
      case 'primary':
      default:
        return {
          icon: CheckCircle2,
          iconBg: 'bg-[var(--color-primary)]/20 text-[var(--color-primary)]',
          btnBg: 'bg-[var(--color-primary)] hover:bg-[var(--color-primary-hover)] text-[var(--color-on-primary)] font-bold focus:ring-[var(--color-primary)]',
        };
    }
  };

  const styles = getVariantStyles();
  const Icon = styles.icon;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={title} maxWidth="md">
      <div className="flex items-start gap-4">
        <div className={`p-3 rounded-xl shrink-0 ${styles.iconBg}`}>
          <Icon className="w-6 h-6" />
        </div>
        <div className="flex-1">
          <p className="text-sm text-[var(--color-muted)] leading-relaxed">{message}</p>
        </div>
      </div>

      <div className="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-[var(--color-border)]">
        <button
          id="confirm-dialog-cancel-btn"
          type="button"
          onClick={onClose}
          disabled={isLoading}
          className="px-4 py-2.5 text-sm font-semibold text-[var(--color-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] rounded-xl transition-colors"
        >
          {cancelLabel}
        </button>
        <button
          id="confirm-dialog-confirm-btn"
          type="button"
          onClick={() => {
            void Promise.resolve(onConfirm()).then(onClose).catch(() => undefined);
          }}
          disabled={isLoading}
          className={`px-5 py-2.5 text-sm font-semibold rounded-xl shadow-xs transition-colors focus:ring-2 focus:ring-offset-2 ${styles.btnBg}`}
        >
          {isLoading ? 'Processing...' : confirmLabel}
        </button>
      </div>
    </Modal>
  );
};
