import { useId, useState } from 'react';
import { Activity, AlertCircle, Eye, MousePointerClick, RefreshCw, Users } from 'lucide-react';
import type { AdminTrafficPeriod, AdminWebTrafficAnalytics } from '../../types';
import { formatNumber } from '../../utils/formatters';

interface WebTrafficOverviewChartProps {
  data: AdminWebTrafficAnalytics | null;
  period: AdminTrafficPeriod;
  loading: boolean;
  error?: string;
  onPeriodChange: (period: AdminTrafficPeriod) => void;
  onRetry: () => void;
}

const periodOptions: { value: AdminTrafficPeriod; label: string }[] = [
  { value: '24h', label: 'Last 24 hours' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
];
const chart = { width: 820, height: 240, left: 48, right: 18, top: 18, bottom: 36 };

const periodLabel = (value: string, granularity: 'hour' | 'day') => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return granularity === 'hour'
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
};

const percent = (value: number | null) => value === null ? 'Not available' : `${value.toFixed(1)}%`;
const axisLabel = (value: number) => value >= 1_000
  ? new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)
  : String(value);

const integerAxis = (maximum: number) => {
  if (maximum <= 4) {
    const axisMaximum = Math.max(1, Math.ceil(maximum));
    return {
      maximum: axisMaximum,
      ticks: Array.from({ length: axisMaximum + 1 }, (_value, index) => index),
    };
  }

  const step = Math.max(1, Math.ceil(maximum / 4));
  return {
    maximum: step * 4,
    ticks: Array.from({ length: 5 }, (_value, index) => index * step),
  };
};

export function WebTrafficOverviewChart({
  data,
  period,
  loading,
  error = '',
  onPeriodChange,
  onRetry,
}: WebTrafficOverviewChartProps) {
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);
  const id = useId().replaceAll(':', '');
  const gradientId = `web-traffic-gradient-${id}`;
  const glowId = `web-traffic-glow-${id}`;
  const plotWidth = chart.width - chart.left - chart.right;
  const plotHeight = chart.height - chart.top - chart.bottom;
  const observedMaximum = Math.max(...(data?.series.map((point) => point.trackedVisitors) ?? []), 0);
  const yAxis = integerAxis(observedMaximum);
  const hasTraffic = (data?.totals.pageViews ?? 0) > 0;
  const x = (index: number) => chart.left + (data && data.series.length > 1 ? (index / (data.series.length - 1)) * plotWidth : plotWidth / 2);
  const y = (value: number) => chart.top + plotHeight - (value / yAxis.maximum) * plotHeight;
  const chartPoints = data?.series.map((point, index) => ({ x: x(index), y: y(point.trackedVisitors) })) ?? [];
  const linePath = chartPoints.reduce((path, point, index, points) => {
    if (index === 0) return `M ${point.x} ${point.y}`;
    const previous = points[index - 1];
    const middle = (previous.x + point.x) / 2;
    return `${path} C ${middle} ${previous.y}, ${middle} ${point.y}, ${point.x} ${point.y}`;
  }, '');
  const areaPath = chartPoints.length > 0
    ? `${linePath} L ${chartPoints.at(-1)?.x ?? chart.left} ${chart.top + plotHeight} L ${chartPoints[0].x} ${chart.top + plotHeight} Z`
    : '';
  const labels = data?.series.reduce<number[]>((indexes, _point, index, points) => {
    const stride = Math.max(1, Math.ceil((points.length - 1) / 5));
    if (index === 0 || index === points.length - 1 || index % stride === 0) indexes.push(index);
    return indexes;
  }, []) ?? [];
  const hoveredPoint = hoveredIndex === null ? null : data?.series[hoveredIndex] ?? null;
  const tooltipLeft = hoveredIndex === null ? 50 : Math.min(87, Math.max(13, (x(hoveredIndex) / chart.width) * 100));

  return (
    <div className="space-y-3">
      <section
        className="overflow-hidden rounded-2xl border border-[#FF8A00]/20 bg-[#061113] text-[#F8FAFC] shadow-[inset_0_1px_0_rgba(255,138,0,0.06),0_18px_50px_rgba(0,0,0,0.24)]"
        aria-labelledby="web-traffic-overview-title"
      >
        <div className="flex flex-col gap-3 border-b border-white/[0.07] px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-5">
          <div>
            <div className="flex items-center gap-2">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-[#FF8A00]/25 bg-[#FF8A00]/10 text-[#FF8A00]"><Activity className="h-3.5 w-3.5" /></span>
              <h2 id="web-traffic-overview-title" className="text-sm font-black tracking-tight">Web Traffic Overview</h2>
            </div>
            <p className="mt-1.5 text-[11px] text-[#94A3B8]">Real frontend navigation tracked by AuditSEO.</p>
          </div>
          <div className="inline-flex flex-wrap self-start rounded-xl border border-white/[0.08] bg-[#081A1C] p-1" aria-label="Web traffic period">
            {periodOptions.map((option) => (
              <button
                key={option.value}
                type="button"
                disabled={loading}
                onClick={() => {
                  setHoveredIndex(null);
                  onPeriodChange(option.value);
                }}
                className={`rounded-lg px-2.5 py-1.5 text-[9px] font-black transition-all disabled:opacity-50 sm:px-3 ${period === option.value ? 'bg-[#FF8A00] text-[#061113] shadow-[0_0_18px_rgba(255,138,0,0.28)]' : 'text-[#94A3B8] hover:bg-white/[0.04] hover:text-[#F8FAFC]'}`}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>

        {loading ? (
          <div className="flex h-64 items-center justify-center gap-2 text-xs text-[#94A3B8]">
            <RefreshCw className="h-4 w-4 animate-spin text-[#FF8A00]" /> Loading real web traffic...
          </div>
        ) : error ? (
          <div className="flex h-64 flex-col items-center justify-center p-6 text-center">
            <AlertCircle className="h-6 w-6 text-[var(--color-danger-text)]" />
            <p className="mt-3 text-xs text-[#94A3B8]">{error}</p>
            <button type="button" onClick={onRetry} className="mt-4 rounded-lg border border-[#FF8A00]/30 bg-[#FF8A00]/10 px-4 py-2 text-xs font-black text-[#FF8A00]">Retry web traffic</button>
          </div>
        ) : !data ? null : (
          <>
            <div className="grid grid-cols-2 gap-px border-b border-white/[0.07] bg-white/[0.06] lg:grid-cols-4">
              {[
                { label: 'Page Views', value: formatNumber(data.totals.pageViews), icon: Eye },
                { label: 'Tracked Visitors', value: formatNumber(data.totals.trackedVisitors), icon: Users },
                { label: 'Sessions', value: formatNumber(data.totals.sessions), icon: MousePointerClick },
                { label: 'Bounce Rate', value: percent(data.totals.bounceRate), icon: Activity },
              ].map(({ label, value, icon: Icon }) => (
                <div key={label} className="bg-[#081A1C] px-4 py-3">
                  <div className="flex items-center gap-2 text-[8px] font-black uppercase tracking-[0.12em] text-[#94A3B8]"><Icon className="h-3 w-3 text-[#FF8A00]" />{label}</div>
                  <p className="mt-1.5 text-lg font-black tracking-tight text-[#F8FAFC]">{value}</p>
                </div>
              ))}
            </div>

            {!hasTraffic ? (
              <div className="m-4 flex h-48 flex-col items-center justify-center rounded-xl border border-dashed border-[#FF8A00]/20 bg-[#081A1C] p-5 text-center sm:m-5">
                <Eye className="h-6 w-6 text-[#FF8A00]/70" />
                <p className="mt-3 text-sm font-black">No web traffic data yet.</p>
                <p className="mt-1 text-xs text-[#94A3B8]">Navigate through the app to start collecting page views.</p>
              </div>
            ) : (
              <div className="bg-[#061113] px-4 pb-3 pt-4 sm:px-5">
                <div className="mb-2 flex items-center gap-2 text-[9px] font-bold uppercase tracking-[0.12em] text-[#94A3B8]"><span className="h-2 w-2 rounded-full bg-[#FF8A00] shadow-[0_0_10px_rgba(255,138,0,0.75)]" />Tracked visitors</div>
                <div className="overflow-x-auto" role="img" aria-label={`Tracked web visitors for ${period}`}>
                  <div className="relative min-w-[680px]" onMouseLeave={() => setHoveredIndex(null)}>
                    {hoveredPoint ? (
                      <div className="pointer-events-none absolute top-3 z-10 w-52 -translate-x-1/2 rounded-lg border border-[#FF8A00]/35 bg-[#071416]/95 p-3 shadow-[0_12px_36px_rgba(0,0,0,0.48),0_0_20px_rgba(255,138,0,0.12)] backdrop-blur" style={{ left: `${tooltipLeft}%` }}>
                        <p className="mb-2 border-b border-white/[0.07] pb-2 text-[10px] font-black uppercase tracking-wide text-[#F8FAFC]">{periodLabel(hoveredPoint.period, data.metadata.granularity)}</p>
                        <div className="space-y-1.5 text-[10px]">
                          <div className="flex justify-between gap-4 text-[#94A3B8]"><span>Tracked visitors</span><strong className="text-[#FF8A00]">{formatNumber(hoveredPoint.trackedVisitors)}</strong></div>
                          <div className="flex justify-between gap-4 text-[#94A3B8]"><span>Page views</span><strong className="text-[#F8FAFC]">{formatNumber(hoveredPoint.pageViews)}</strong></div>
                          <div className="flex justify-between gap-4 text-[#94A3B8]"><span>Sessions</span><strong className="text-[#F8FAFC]">{formatNumber(hoveredPoint.sessions)}</strong></div>
                          {hoveredPoint.bounceRate === null ? null : <div className="flex justify-between gap-4 text-[#94A3B8]"><span>Bounce rate</span><strong className="text-[#F97316]">{percent(hoveredPoint.bounceRate)}</strong></div>}
                        </div>
                      </div>
                    ) : null}
                    <svg viewBox={`0 0 ${chart.width} ${chart.height}`} preserveAspectRatio="none" className="h-[240px] w-full" aria-hidden="true">
                      <defs>
                        <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
                          <stop offset="0%" stopColor="#FF8A00" stopOpacity="0.38" />
                          <stop offset="52%" stopColor="#F97316" stopOpacity="0.16" />
                          <stop offset="100%" stopColor="#FF8A00" stopOpacity="0.02" />
                        </linearGradient>
                        <filter id={glowId} x="-40%" y="-40%" width="180%" height="180%"><feDropShadow dx="0" dy="0" stdDeviation="4" floodColor="#FF8A00" floodOpacity="0.65" /></filter>
                      </defs>
                      {yAxis.ticks.map((tick) => {
                        const lineY = y(tick);
                        return (
                          <g key={tick}>
                            <line x1={chart.left} x2={chart.width - chart.right} y1={lineY} y2={lineY} stroke="rgba(148,163,184,0.12)" strokeWidth="1" strokeDasharray="3 6" />
                            <text x={chart.left - 8} y={lineY + 4} textAnchor="end" fill="#94A3B8" fontSize="10">{axisLabel(tick)}</text>
                          </g>
                        );
                      })}
                      <path d={areaPath} fill={`url(#${gradientId})`} />
                      <path d={linePath} fill="none" stroke="#FF8A00" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
                      {chartPoints.map((point, index) => <circle key={data.series[index].period} cx={point.x} cy={point.y} r={hoveredIndex === index ? 6 : 3.5} fill="#FF8A00" stroke="#061113" strokeWidth={hoveredIndex === index ? 3 : 2} filter={hoveredIndex === index ? `url(#${glowId})` : undefined} />)}
                      {hoveredIndex !== null ? <line x1={x(hoveredIndex)} x2={x(hoveredIndex)} y1={chart.top} y2={chart.top + plotHeight} stroke="#FF8A00" strokeWidth="1" strokeDasharray="3 4" opacity="0.35" /> : null}
                      {data.series.map((point, index) => {
                        const interactionWidth = data.series.length > 1 ? plotWidth / (data.series.length - 1) : plotWidth;
                        const regionX = Math.max(chart.left, x(index) - interactionWidth / 2);
                        const regionRight = Math.min(chart.width - chart.right, x(index) + interactionWidth / 2);
                        return <rect key={`hover-${point.period}`} x={regionX} y={chart.top} width={regionRight - regionX} height={plotHeight} fill="transparent" onMouseEnter={() => setHoveredIndex(index)} />;
                      })}
                      {labels.map((index) => <text key={`label-${data.series[index].period}`} x={x(index)} y={chart.height - 11} textAnchor="middle" fill="#94A3B8" fontSize="10">{periodLabel(data.series[index].period, data.metadata.granularity)}</text>)}
                    </svg>
                  </div>
                </div>
              </div>
            )}
          </>
        )}
      </section>

      {!loading && !error && data ? (
        <section className="overflow-hidden rounded-2xl border border-[#FF8A00]/15 bg-[#071416] text-[#F8FAFC] shadow-[0_12px_34px_rgba(0,0,0,0.18)]" aria-labelledby="top-pages-title">
          <div className="flex items-center justify-between px-4 py-3 sm:px-5">
            <div><h3 id="top-pages-title" className="text-xs font-black">Top pages</h3><p className="mt-0.5 text-[9px] text-[#94A3B8]">Real page-view activity for the selected period.</p></div>
            <span className="rounded-full border border-[#FF8A00]/20 bg-[#FF8A00]/[0.07] px-2 py-1 text-[8px] font-black uppercase tracking-wider text-[#FF8A00]">First-party</span>
          </div>
          {data.topPages.length === 0 ? (
            <p className="border-t border-white/[0.06] px-4 py-5 text-[11px] text-[#94A3B8] sm:px-5">No top pages yet.</p>
          ) : (
            <div className="max-h-[170px] overflow-auto border-t border-white/[0.06]">
              <table className="w-full min-w-[620px] text-left text-[11px]">
                <thead className="sticky top-0 bg-[#081A1C] text-[8px] uppercase tracking-[0.12em] text-[#94A3B8]"><tr><th className="px-4 py-2.5 font-black sm:px-5">Page</th><th className="px-3 py-2.5 text-right font-black">Page views</th><th className="px-3 py-2.5 text-right font-black">Tracked visitors</th><th className="px-4 py-2.5 text-right font-black sm:px-5">Sessions</th></tr></thead>
                <tbody className="divide-y divide-white/[0.05]">
                  {data.topPages.map((page) => <tr key={page.path} className="transition-colors hover:bg-white/[0.025]"><td className="max-w-80 truncate px-4 py-2.5 font-mono text-[10px] font-bold text-[#FF8A00] sm:px-5" title={page.path}>{page.path}</td><td className="px-3 py-2.5 text-right font-bold text-[#F8FAFC]">{formatNumber(page.pageViews)}</td><td className="px-3 py-2.5 text-right font-bold text-[#F8FAFC]">{formatNumber(page.trackedVisitors)}</td><td className="px-4 py-2.5 text-right font-bold text-[#F8FAFC] sm:px-5">{formatNumber(page.sessions)}</td></tr>)}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : null}
    </div>
  );
}
