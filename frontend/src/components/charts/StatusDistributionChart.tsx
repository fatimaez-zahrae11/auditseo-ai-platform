import React from 'react';
import type { AuditStatus, SeoAudit } from '../../types';

interface StatusDistributionChartProps {
  audits?: SeoAudit[];
  statusCounts?: Partial<Record<AuditStatus, number>>;
}

export const StatusDistributionChart: React.FC<StatusDistributionChartProps> = ({ audits = [], statusCounts }) => {
  const counts: Record<AuditStatus, number> = { completed: 0, running: 0, pending: 0, failed: 0 };
  audits.forEach((audit) => { counts[audit.status] = (counts[audit.status] || 0) + 1; });
  if (statusCounts) {
    counts.completed = statusCounts.completed ?? 0;
    counts.running = statusCounts.running ?? 0;
    counts.pending = statusCounts.pending ?? 0;
    counts.failed = statusCounts.failed ?? 0;
  }

  const actualTotal = Object.values(counts).reduce((sum, count) => sum + count, 0);
  const total = actualTotal || 1;
  const slices = [
    { label: 'Completed', count: counts.completed, color: '#FF8A00' },
    { label: 'Running', count: counts.running, color: '#67E8F9' },
    { label: 'Pending', count: counts.pending, color: '#F97316' },
    { label: 'Failed', count: counts.failed, color: '#FB7185' },
  ];
  const size = 116;
  const strokeWidth = 17;
  const radius = (size - strokeWidth) / 2;
  const circumference = 2 * Math.PI * radius;
  let cumulativePercent = 0;

  return (
    <div id="status-distribution-chart" className="w-full text-[#F8FAFC]">
      <div className="flex items-start justify-between gap-3"><div><h2 className="text-xs font-black">Audit status</h2><p className="mt-0.5 text-[9px] text-[#94A3B8]">Current indexed jobs</p></div><span className="rounded-full border border-[#FF8A00]/20 bg-[#FF8A00]/10 px-2 py-1 text-[8px] font-black text-[#FF8A00]">{actualTotal} total</span></div>
      <div className="mt-3 flex items-center gap-4">
        <div className="relative flex shrink-0 items-center justify-center">
          <svg width={size} height={size} className="-rotate-90">
            <circle cx={size / 2} cy={size / 2} r={radius} stroke="rgba(148,163,184,0.12)" strokeWidth={strokeWidth} fill="transparent" />
            {slices.map((slice) => {
              if (slice.count === 0) return null;
              const share = slice.count / total;
              const dashOffset = -cumulativePercent * circumference;
              cumulativePercent += share;
              return <circle key={slice.label} cx={size / 2} cy={size / 2} r={radius} stroke={slice.color} strokeWidth={strokeWidth} strokeDasharray={`${share * circumference} ${circumference}`} strokeDashoffset={dashOffset} fill="transparent" className="transition-all duration-700" />;
            })}
          </svg>
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"><span className="text-xl font-black">{actualTotal}</span><span className="text-[7px] font-black uppercase tracking-wider text-[#94A3B8]">Audits</span></div>
        </div>
        <div className="min-w-0 flex-1 space-y-1.5">
          {slices.map((slice) => <div key={slice.label} className="flex items-center justify-between rounded-lg bg-[#081A1C] px-2.5 py-1.5"><span className="flex items-center gap-2 text-[8px] font-bold text-[#94A3B8]"><span className="h-2 w-2 rounded-full" style={{ backgroundColor: slice.color }} />{slice.label}</span><span className="text-[9px] font-black">{slice.count} <span className="font-normal text-[#94A3B8]">· {Math.round((slice.count / total) * 100)}%</span></span></div>)}
        </div>
      </div>
    </div>
  );
};
