import { Inbox } from 'lucide-react';
import type { ReactNode } from 'react';

interface EmptyStateProps {
  title: string;
  description: string;
  action?: ReactNode;
  compact?: boolean;
}

export function EmptyState({ title, description, action, compact = false }: EmptyStateProps) {
  return (
    <div className={`flex flex-col items-center justify-center rounded-2xl border border-dashed border-[var(--color-border)] bg-[var(--color-canvas)]/55 text-center ${compact ? 'px-5 py-10' : 'px-6 py-16'}`}>
      <div className="mb-4 rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3 text-[var(--color-primary)]">
        <Inbox className="h-5 w-5" />
      </div>
      <h3 className="text-sm font-bold text-[var(--color-text)]">{title}</h3>
      <p className="mt-2 max-w-md text-xs leading-5 text-[var(--color-muted)]">{description}</p>
      {action ? <div className="mt-5">{action}</div> : null}
    </div>
  );
}
