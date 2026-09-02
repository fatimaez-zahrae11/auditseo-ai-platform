export function formatNumber(value: number): string {
  return new Intl.NumberFormat('en', {
    notation: value >= 10_000 ? 'compact' : 'standard',
  }).format(value);
}

export function formatDuration(milliseconds?: number): string {
  if (milliseconds === undefined) return '—';
  return milliseconds < 1_000
    ? `${milliseconds} ms`
    : `${(milliseconds / 1_000).toFixed(1)} s`;
}
