import React, { useState } from 'react';
import { Activity, AlertCircle, RefreshCw } from 'lucide-react';
import type { AdminTrafficAnalytics, AdminTrafficPeriod } from '../../types';
import { formatNumber } from '../../utils/formatters';

interface PlatformActivityChartProps {
  data: AdminTrafficAnalytics | null;
  period: AdminTrafficPeriod;
  loading: boolean;
  error?: string;
  onPeriodChange: (period: AdminTrafficPeriod) => void;
  onRetry: () => void;
}

const SERIES = [
  { key: 'requests', label: 'API requests', color: '#FF8A00' },
  { key: 'audits', label: 'Audits', color: '#22D3EE' },
  { key: 'recommendations', label: 'Recommendations', color: '#A3E635' },
  { key: 'httpErrors', label: 'HTTP errors', color: '#FB7185' },
] as const;
const periods: AdminTrafficPeriod[] = ['24h', '7d', '30d'];
const chartWidth = 760;
const chartHeight = 220;
const plot = { left: 44, right: 16, top: 14, bottom: 34 };

const labelForPeriod = (value: string, granularity: 'hour' | 'day') => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return granularity === 'hour'
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
};

const integerAxis = (maximum: number) => {
  if (maximum <= 4) {
    const axisMaximum = Math.max(1, Math.ceil(maximum));
    return { maximum: axisMaximum, ticks: Array.from({ length: axisMaximum + 1 }, (_value, index) => index) };
  }
  const step = Math.max(1, Math.ceil(maximum / 4));
  return { maximum: step * 4, ticks: Array.from({ length: 5 }, (_value, index) => index * step) };
};

const smoothPath = (points: { x: number; y: number }[]) => points.reduce((path, point, index) => {
  if (index === 0) return `M ${point.x} ${point.y}`;
  const previous = points[index - 1];
  const middle = (previous.x + point.x) / 2;
  return `${path} C ${middle} ${previous.y}, ${middle} ${point.y}, ${point.x} ${point.y}`;
}, '');

export const PlatformActivityChart: React.FC<PlatformActivityChartProps> = ({
  data,
  period,
  loading,
  error = '',
  onPeriodChange,
  onRetry,
}) => {
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);
  const allValues = data?.series.flatMap((point) => [point.requests, point.audits, point.recommendations, point.httpErrors]) ?? [];
  const observedMaximum = Math.max(...allValues, 0);
  const axis = integerAxis(observedMaximum);
  const hasActivity = observedMaximum > 0;
  const plotWidth = chartWidth - plot.left - plot.right;
  const plotHeight = chartHeight - plot.top - plot.bottom;
  const x = (index: number) => plot.left + (data && data.series.length > 1 ? (index / (data.series.length - 1)) * plotWidth : plotWidth / 2);
  const y = (value: number) => plot.top + plotHeight - (value / axis.maximum) * plotHeight;
  const labelIndexes = data?.series.reduce<number[]>((indexes, _point, index, points) => {
    const stride = Math.max(1, Math.ceil((points.length - 1) / 5));
    if (index === 0 || index === points.length - 1 || index % stride === 0) indexes.push(index);
    return indexes;
  }, []) ?? [];
  const hoveredPoint = hoveredIndex === null ? null : data?.series[hoveredIndex] ?? null;
  const hoveredLeft = hoveredIndex === null ? 50 : Math.min(87, Math.max(13, (x(hoveredIndex) / chartWidth) * 100));

  return (
    <div id="platform-activity-chart" className="w-full text-[#F8FAFC]">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <div className="flex items-center gap-2"><Activity className="h-3.5 w-3.5 text-[#FF8A00]" /><h2 className="text-xs font-black">Platform API Activity</h2></div>
          <p className="mt-1 text-[9px] text-[#94A3B8]">Real backend requests, audits, recommendations, and HTTP errors — not website visitors.</p>
        </div>
        <div className="inline-flex self-start rounded-lg border border-white/[0.07] bg-[#081A1C] p-1" aria-label="Platform API activity period">
          {periods.map((option) => <button key={option} type="button" disabled={loading} onClick={() => { setHoveredIndex(null); onPeriodChange(option); }} className={`rounded-md px-2.5 py-1 text-[8px] font-black uppercase transition-colors disabled:opacity-50 ${period === option ? 'bg-[#FF8A00] text-[#061113]' : 'text-[#94A3B8] hover:text-[#F8FAFC]'}`}>{option}</button>)}
        </div>
      </div>

      {loading ? (
        <div className="flex h-56 items-center justify-center gap-2 text-[10px] text-[#94A3B8]"><RefreshCw className="h-3.5 w-3.5 animate-spin text-[#FF8A00]" />Loading real API activity...</div>
      ) : error ? (
        <div className="flex h-56 flex-col items-center justify-center text-center"><AlertCircle className="h-5 w-5 text-[var(--color-danger-text)]" /><p className="mt-2 text-[10px] text-[#94A3B8]">{error}</p><button type="button" onClick={onRetry} className="mt-3 rounded-lg border border-[#FF8A00]/25 px-3 py-1.5 text-[9px] font-black text-[#FF8A00]">Retry API activity</button></div>
      ) : !data || !hasActivity ? (
        <div className="mt-3 flex h-52 flex-col items-center justify-center rounded-xl border border-dashed border-[#FF8A00]/15 bg-[#081A1C] text-center"><Activity className="h-5 w-5 text-[#94A3B8]" /><p className="mt-2 text-[11px] font-bold">No platform API activity in this period</p><p className="mt-1 text-[9px] text-[#94A3B8]">All real activity counters are zero.</p></div>
      ) : (
        <>
          <div className="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-white/[0.06] lg:grid-cols-4">
            {SERIES.map((series) => <div key={series.key} className="bg-[#081A1C] px-3 py-2"><div className="flex items-center gap-1.5 text-[7px] font-black uppercase tracking-wide text-[#94A3B8]"><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: series.color }} />{series.label}</div><p className="mt-1 text-sm font-black">{formatNumber(data.totals[series.key])}</p></div>)}
          </div>

          <div className="mt-2 overflow-x-auto" role="img" aria-label={`Platform API activity for ${period}`}>
            <div className="relative min-w-[640px]" onMouseLeave={() => setHoveredIndex(null)}>
              {hoveredPoint ? (
                <div className="pointer-events-none absolute top-2 z-10 w-44 -translate-x-1/2 rounded-lg border border-[#FF8A00]/25 bg-[#071416]/95 p-2.5 shadow-[0_12px_32px_rgba(0,0,0,0.45)]" style={{ left: `${hoveredLeft}%` }}>
                  <p className="mb-1.5 border-b border-white/[0.06] pb-1.5 text-[9px] font-black uppercase">{labelForPeriod(hoveredPoint.period, data.metadata.granularity)}</p>
                  <div className="space-y-1">{SERIES.map((series) => <div key={series.key} className="flex items-center justify-between gap-3 text-[8px]"><span className="flex items-center gap-1.5 text-[#94A3B8]"><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: series.color }} />{series.label}</span><span className="font-black">{formatNumber(hoveredPoint[series.key])}</span></div>)}</div>
                </div>
              ) : null}

              <svg viewBox={`0 0 ${chartWidth} ${chartHeight}`} preserveAspectRatio="none" className="h-[220px] w-full" aria-hidden="true">
                {axis.ticks.map((tick) => {
                  const lineY = y(tick);
                  return <g key={tick}><line x1={plot.left} x2={chartWidth - plot.right} y1={lineY} y2={lineY} stroke="rgba(148,163,184,0.12)" strokeWidth="1" strokeDasharray="3 5" /><text x={plot.left - 7} y={lineY + 3} textAnchor="end" fill="#94A3B8" fontSize="9">{formatNumber(tick)}</text></g>;
                })}
                {SERIES.map((series) => {
                  const points = data.series.map((point, index) => ({ x: x(index), y: y(point[series.key]) }));
                  return <g key={series.key}><path d={smoothPath(points)} fill="none" stroke={series.color} strokeWidth={series.key === 'requests' ? 3 : 2} strokeLinejoin="round" strokeLinecap="round" />{points.map((point, index) => <circle key={`${series.key}-${data.series[index].period}`} cx={point.x} cy={point.y} r={hoveredIndex === index ? 4 : 2.25} fill={series.color} stroke="#061113" strokeWidth="1.5" />)}</g>;
                })}
                {hoveredIndex !== null ? <line x1={x(hoveredIndex)} x2={x(hoveredIndex)} y1={plot.top} y2={plot.top + plotHeight} stroke="#FF8A00" strokeWidth="1" strokeDasharray="3 4" opacity="0.4" /> : null}
                {data.series.map((point, index) => {
                  const interactionWidth = data.series.length > 1 ? plotWidth / (data.series.length - 1) : plotWidth;
                  const regionX = Math.max(plot.left, x(index) - interactionWidth / 2);
                  const regionRight = Math.min(chartWidth - plot.right, x(index) + interactionWidth / 2);
                  return <rect key={`interaction-${point.period}`} x={regionX} y={plot.top} width={regionRight - regionX} height={plotHeight} fill="transparent" onMouseEnter={() => setHoveredIndex(index)} />;
                })}
                {labelIndexes.map((index) => <text key={data.series[index].period} x={x(index)} y={chartHeight - 9} textAnchor="middle" fill="#94A3B8" fontSize="9">{labelForPeriod(data.series[index].period, data.metadata.granularity)}</text>)}
              </svg>
            </div>
          </div>
        </>
      )}
    </div>
  );
};
