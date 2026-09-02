import { AlertTriangle, RefreshCw } from 'lucide-react';

interface RequestErrorStateProps {
  title: string;
  message: string;
  onRetry?: () => void;
  retryLabel?: string;
}

export function RequestErrorState({ title, message, onRetry, retryLabel = 'Try again' }: RequestErrorStateProps) {
  return (
    <div className="flex min-h-64 flex-col items-center justify-center rounded-3xl border border-[var(--color-danger-border)] bg-[var(--color-surface)] p-8 text-center shadow-lg" role="alert">
      <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--color-danger-bg)] text-[var(--color-danger-text)]"><AlertTriangle className="h-5 w-5" /></div>
      <h2 className="mt-4 text-base font-black text-[var(--color-text)]">{title}</h2>
      <p className="mt-2 max-w-lg text-xs leading-5 text-[var(--color-muted)]">{message}</p>
      {onRetry ? <button type="button" onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-xl bg-[var(--color-primary)] px-4 py-2.5 text-xs font-black text-[var(--color-on-primary)] hover:bg-[var(--color-primary-hover)]"><RefreshCw className="h-3.5 w-3.5" />{retryLabel}</button> : null}
    </div>
  );
}
