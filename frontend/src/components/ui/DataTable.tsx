import type { ReactNode } from 'react';
import { EmptyState } from './EmptyState';

export interface DataTableColumn<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  align?: 'left' | 'center' | 'right';
  className?: string;
}

interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  onRowClick?: (row: T) => void;
  emptyTitle?: string;
  emptyDescription?: string;
  dense?: boolean;
}

const alignments = {
  left: 'text-left',
  center: 'text-center',
  right: 'text-right',
};

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  onRowClick,
  emptyTitle = 'No records yet',
  emptyDescription = 'There is nothing to display for the current filters.',
  dense = false,
}: DataTableProps<T>) {
  if (rows.length === 0) {
    return <EmptyState title={emptyTitle} description={emptyDescription} compact />;
  }

  return (
    <div className="overflow-x-auto rounded-2xl border border-[var(--color-border)]">
      <table className="w-full min-w-[720px] border-collapse text-left text-xs">
        <thead className="bg-[var(--color-canvas)]">
          <tr className="border-b border-[var(--color-border)]">
            {columns.map((column) => (
              <th
                key={column.key}
                className={`px-4 py-3 text-[10px] font-extrabold uppercase tracking-[0.12em] text-[var(--color-muted)] ${alignments[column.align ?? 'left']} ${column.className ?? ''}`}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-[var(--color-border)]/70 bg-[var(--color-surface)]">
          {rows.map((row) => (
            <tr
              key={rowKey(row)}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              className={`${onRowClick ? 'cursor-pointer' : ''} transition-colors hover:bg-[var(--color-surface-muted)]/35`}
            >
              {columns.map((column) => (
                <td
                  key={column.key}
                  className={`${dense ? 'px-4 py-2.5' : 'px-4 py-3.5'} text-[var(--color-text)] ${alignments[column.align ?? 'left']} ${column.className ?? ''}`}
                >
                  {column.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
