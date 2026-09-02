import { Loader2 } from 'lucide-react';

export function LoadingState({ label = 'Loading workspace data...' }: { label?: string }) {
  return (
    <div className="flex min-h-64 flex-col items-center justify-center rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] p-8 text-center shadow-lg" role="status">
      <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--color-highlight)] text-[var(--color-on-highlight)]">
        <Loader2 className="h-5 w-5 animate-spin" />
      </div>
      <p className="mt-4 text-sm font-black text-[var(--color-text)]">{label}</p>
      <p className="mt-1 text-xs text-[var(--color-muted)]">Securely retrieving the latest workspace information.</p>
    </div>
  );
}
