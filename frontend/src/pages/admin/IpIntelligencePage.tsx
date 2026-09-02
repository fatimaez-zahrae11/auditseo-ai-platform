import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Activity,
  AlertTriangle,
  Crosshair,
  Globe2,
  MapPin,
  RefreshCw,
  ShieldAlert,
} from 'lucide-react';
import { EmptyState } from '../../components/ui/EmptyState';
import { LoadingState } from '../../components/ui/LoadingState';
import { adminSecurityService } from '../../services/adminSecurityService';
import type {
  AdminIpIntelligenceData,
  AdminIpMapPoint,
  AdminIpRiskLevel,
  AdminTrafficPeriod,
} from '../../types';
import { adminReadErrorMessage } from '../../utils/adminApiErrors';
import { formatNumber } from '../../utils/formatters';

const riskStyles: Record<AdminIpRiskLevel, string> = {
  critical: 'border-fuchsia-500/50 bg-fuchsia-500/15 text-fuchsia-200',
  high: 'border-rose-500/50 bg-rose-500/15 text-rose-200',
  medium: 'border-amber-400/50 bg-amber-400/15 text-amber-100',
  low: 'border-emerald-400/40 bg-emerald-400/10 text-emerald-200',
};
const riskColors: Record<AdminIpRiskLevel, string> = {
  critical: '#E879F9', high: '#FB7185', medium: '#FACC15', low: '#06D6A0',
};
const formatDate = (value: string | null) => {
  if (!value) return 'Not available';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Not available' : date.toLocaleString();
};

function RiskCard({ level, count }: { level: AdminIpRiskLevel; count: number }) {
  return (
    <article className={`relative overflow-hidden rounded-2xl border p-5 shadow-xl ${riskStyles[level]}`}>
      <div className="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-current opacity-5 blur-2xl" />
      <div className="flex items-center justify-between"><p className="text-[10px] font-black uppercase tracking-[0.18em]">{level}</p><ShieldAlert className="h-4 w-4" /></div>
      <p className="mt-4 text-3xl font-black text-[var(--color-text)]">{formatNumber(count)}</p>
      <p className="mt-1 text-[10px] font-semibold opacity-80">Current selected period</p>
    </article>
  );
}

function GeographicPlot({ points }: { points: AdminIpMapPoint[] }) {
  const width = 720;
  const height = 330;
  const maximum = Math.max(...points.map((point) => point.requestCount), 1);
  const x = (longitude: number) => ((longitude + 180) / 360) * width;
  const y = (latitude: number) => ((90 - latitude) / 180) * height;

  if (points.length === 0) {
    return <EmptyState title="No public IP geolocation data available yet." description="Configure a local MaxMind GeoLite2 database or allow the cache to accumulate resolved public addresses." compact />;
  }

  return (
    <div className="overflow-x-auto rounded-2xl border border-violet-400/15 bg-[#070A16]">
      <svg viewBox={`0 0 ${width} ${height}`} className="min-w-[620px] w-full" role="img" aria-label="Real geolocated platform IP activity">
        <defs><radialGradient id="security-map-glow"><stop offset="0" stopColor="#7C3AED" stopOpacity="0.24" /><stop offset="1" stopColor="#020C0F" stopOpacity="0" /></radialGradient></defs>
        <rect width={width} height={height} fill="#070A16" />
        <ellipse cx="360" cy="165" rx="310" ry="145" fill="url(#security-map-glow)" />
        {[-120, -60, 0, 60, 120].map((longitude) => <line key={`lon-${longitude}`} x1={x(longitude)} x2={x(longitude)} y1="0" y2={height} stroke="#312E81" strokeOpacity="0.35" strokeDasharray="3 7" />)}
        {[-60, -30, 0, 30, 60].map((latitude) => <line key={`lat-${latitude}`} x1="0" x2={width} y1={y(latitude)} y2={y(latitude)} stroke="#312E81" strokeOpacity="0.35" strokeDasharray="3 7" />)}
        <text x="12" y="18" fill="#8B9BB4" fontSize="10">90°N</text><text x="12" y={height - 10} fill="#8B9BB4" fontSize="10">90°S</text>
        {points.map((point, index) => {
          const radius = 5 + Math.sqrt(point.requestCount / maximum) * 12;
          const color = riskColors[point.riskLevel];
          return (
            <g key={`${point.latitude}-${point.longitude}-${index}`}>
              <circle cx={x(point.longitude)} cy={y(point.latitude)} r={radius + 7} fill={color} opacity="0.1" />
              <circle cx={x(point.longitude)} cy={y(point.latitude)} r={radius} fill={color} opacity="0.78" stroke="#E6FBF6" strokeWidth="1">
                <title>{`${point.city ? `${point.city}, ` : ''}${point.countryName}: ${point.requestCount} requests, ${point.errorCount} errors`}</title>
              </circle>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

export const IpIntelligencePage: React.FC = () => {
  const [data, setData] = useState<AdminIpIntelligenceData | null>(null);
  const [period, setPeriod] = useState<AdminTrafficPeriod>('24h');
  const [risk, setRisk] = useState<AdminIpRiskLevel | 'all'>('all');
  const [country, setCountry] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setData(await adminSecurityService.ipIntelligence({ period, risk: risk === 'all' ? undefined : risk, country: country || undefined, page, perPage: 20 }));
    } catch (requestError) {
      setError(adminReadErrorMessage(requestError, 'IP intelligence'));
    } finally {
      setLoading(false);
    }
  }, [country, page, period, risk]);

  useEffect(() => { void load(); }, [load]);
  const countries = useMemo(() => {
    const values = new Map<string, string>();
    data?.mapPoints.forEach((point) => {
      if (point.countryCode) values.set(point.countryCode, point.countryName);
    });
    if (country && !values.has(country)) values.set(country, country);
    return [...values.entries()].sort((a, b) => a[1].localeCompare(b[1]));
  }, [country, data]);
  const maximumHeat = Math.max(...(data?.topAddressesHeatmap.map((item) => item.requestCount + item.errorCount * 4) ?? []), 1);

  return (
    <div id="ip-intelligence-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <header className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
        <div><div className="inline-flex items-center gap-2 rounded-full border border-violet-400/30 bg-violet-500/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-violet-200"><Crosshair className="h-3.5 w-3.5" /> API security</div><h1 className="mt-3 text-3xl font-black tracking-tight">IP Intelligence</h1><p className="mt-2 text-sm text-[var(--color-muted)]">Real platform access monitoring from masked Laravel access-log addresses and cached geolocation.</p></div>
        <button onClick={() => void load()} disabled={loading} className="inline-flex min-h-10 items-center gap-2 self-start rounded-xl border border-violet-400/20 bg-[var(--color-surface)] px-4 text-xs font-black hover:border-violet-400/60 disabled:opacity-50"><RefreshCw className={`h-4 w-4 text-violet-300 ${loading ? 'animate-spin' : ''}`} /> Refresh intelligence</button>
      </header>

      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Risk summary">
        {(['critical', 'high', 'medium', 'low'] as AdminIpRiskLevel[]).map((level) => <RiskCard key={level} level={level} count={data?.summary[level] ?? 0} />)}
      </section>

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {[{ label: 'Unique masked IPs', value: data?.summary.uniqueIps ?? 0 }, { label: 'Geolocated countries', value: data?.summary.countriesCount ?? 0 }, { label: 'Platform requests', value: data?.summary.requestsCount ?? 0 }, { label: 'HTTP errors', value: data?.summary.errorsCount ?? 0 }].map((item) => <div key={item.label} className="rounded-2xl border border-violet-400/15 bg-[var(--color-surface)] px-4 py-3"><p className="text-[9px] font-black uppercase tracking-wider text-[var(--color-muted)]">{item.label}</p><p className="mt-1 text-xl font-black">{formatNumber(item.value)}</p></div>)}
      </section>

      <section className="rounded-3xl border border-violet-400/15 bg-[var(--color-surface)] p-5 shadow-xl">
        <div className="grid gap-3 lg:grid-cols-12">
          <label className="lg:col-span-3"><span className="mb-1.5 block text-[10px] font-black uppercase text-[var(--color-muted)]">Period</span><select value={period} onChange={(event) => { setPage(1); setPeriod(event.target.value as AdminTrafficPeriod); }} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2.5 text-xs"><option value="24h">Last 24 hours</option><option value="7d">Last 7 days</option><option value="30d">Last 30 days</option></select></label>
          <label className="lg:col-span-3"><span className="mb-1.5 block text-[10px] font-black uppercase text-[var(--color-muted)]">Risk</span><select value={risk} onChange={(event) => { setPage(1); setRisk(event.target.value as AdminIpRiskLevel | 'all'); }} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2.5 text-xs"><option value="all">All risk levels</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></label>
          <label className="lg:col-span-4"><span className="mb-1.5 block text-[10px] font-black uppercase text-[var(--color-muted)]">Country</span><select value={country} onChange={(event) => { setPage(1); setCountry(event.target.value); }} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2.5 text-xs"><option value="">All available countries</option>{countries.map(([code, name]) => <option key={code} value={code}>{name} ({code})</option>)}</select></label>
          <div className="flex items-end lg:col-span-2"><button type="button" onClick={() => { setPeriod('24h'); setRisk('all'); setCountry(''); setPage(1); }} className="w-full rounded-xl border border-[var(--color-border)] px-3 py-2.5 text-xs font-bold">Reset filters</button></div>
        </div>
      </section>

      {loading && !data ? <LoadingState label="Aggregating real IP security signals..." /> : null}
      {error ? <div className="rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-5 text-sm text-[var(--color-danger-text)]"><p className="font-bold">IP intelligence unavailable</p><p className="mt-1">{error}</p></div> : null}

      {data && !error ? <>
        <section className="grid gap-6 xl:grid-cols-12">
          <article className="rounded-3xl border border-violet-400/15 bg-[var(--color-surface)] p-5 shadow-xl xl:col-span-5"><div className="mb-5 flex items-center justify-between"><div><h2 className="text-sm font-black">Top Addresses</h2><p className="mt-1 text-xs text-[var(--color-muted)]">Intensity uses real requests and HTTP errors.</p></div><Activity className="h-4 w-4 text-violet-300" /></div>{data.topAddressesHeatmap.length === 0 ? <EmptyState title="No addresses recorded" description="No access-log IPs match the current filters." compact /> : <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-2 2xl:grid-cols-4">{data.topAddressesHeatmap.map((item) => { const intensity = 0.2 + ((item.requestCount + item.errorCount * 4) / maximumHeat) * 0.8; return <div key={item.ipMasked} className={`rounded-xl border p-3 ${riskStyles[item.riskLevel]}`} style={{ opacity: intensity }} title={`${item.requestCount} requests, ${item.errorCount} errors, score ${item.riskScore}`}><p className="truncate font-mono text-[10px] font-black">{item.label}</p><div className="mt-2 flex items-end justify-between"><span className="text-lg font-black">{formatNumber(item.requestCount)}</span><span className="text-[9px] uppercase">{item.errorCount} errors</span></div></div>; })}</div>}</article>
          <article className="rounded-3xl border border-violet-400/15 bg-[var(--color-surface)] p-5 shadow-xl xl:col-span-7"><div className="mb-5 flex items-center justify-between"><div><h2 className="text-sm font-black">IP Locations</h2><p className="mt-1 text-xs text-[var(--color-muted)]">Equirectangular plot using cached coordinates only.</p></div><Globe2 className="h-4 w-4 text-violet-300" /></div><GeographicPlot points={data.mapPoints} /></article>
        </section>

        <section className="rounded-3xl border border-violet-400/15 bg-[var(--color-surface)] shadow-xl"><div className="flex items-center gap-2 border-b border-[var(--color-border)] p-5"><AlertTriangle className="h-4 w-4 text-amber-300" /><div><h2 className="text-sm font-black">External Exposure</h2><p className="mt-1 text-xs text-[var(--color-muted)]">Elevated behavior derived from recorded status and route counters.</p></div></div>{data.externalExposure.length === 0 ? <div className="p-5"><EmptyState title="No elevated activity detected" description="No medium, high, or critical IP behavior matches the current filters." compact /></div> : <div className="grid gap-3 p-5 md:grid-cols-2">{data.externalExposure.map((item) => <div key={`${item.ipMasked}-${item.title}`} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-4"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-black">{item.title}</p><p className="mt-1 font-mono text-[10px] text-[var(--color-muted)]">{item.ipMasked}</p></div><span className={`rounded-full border px-2 py-1 text-[9px] font-black uppercase ${riskStyles[item.riskLevel]}`}>{item.riskLevel}</span></div><p className="mt-3 text-xs text-[var(--color-muted)]">{item.reason} · {formatNumber(item.requestCount)} requests</p><p className="mt-2 text-[10px] text-[var(--color-muted)]">Last seen {formatDate(item.lastSeenAt)}</p></div>)}</div>}</section>

        <section className="overflow-hidden rounded-3xl border border-violet-400/15 bg-[var(--color-surface)] shadow-xl"><div className="flex items-center justify-between border-b border-[var(--color-border)] p-5"><div><h2 className="text-sm font-black">View Results By Address</h2><p className="mt-1 text-xs text-[var(--color-muted)]">Masked addresses with transparent risk evidence.</p></div><MapPin className="h-4 w-4 text-violet-300" /></div>{data.results.length === 0 ? <div className="p-5"><EmptyState title="No IP results" description="No real access-log addresses match the selected filters." compact /></div> : <div className="overflow-x-auto"><table className="w-full min-w-[1120px] text-left text-xs"><thead className="bg-[#070A16] text-[10px] uppercase tracking-wide text-[var(--color-muted)]"><tr><th className="px-5 py-3">IP masked</th><th className="px-4 py-3">Location</th><th className="px-4 py-3">Users</th><th className="px-4 py-3">Requests</th><th className="px-4 py-3">Errors</th><th className="px-4 py-3">Risk score</th><th className="px-4 py-3">Risk level</th><th className="px-5 py-3">Last seen</th></tr></thead><tbody className="divide-y divide-[var(--color-border)]/60">{data.results.map((item) => <tr key={item.ipMasked} className="hover:bg-violet-500/5"><td className="px-5 py-4"><p className="font-mono font-black text-violet-200">{item.ipMasked}</p><p className="mt-1 text-[10px] text-[var(--color-muted)]">{item.distinctRoutesCount} distinct routes</p></td><td className="px-4 py-4"><p className="font-bold">{item.city ? `${item.city}, ${item.countryName}` : item.countryName}</p><p className="mt-1 text-[10px] text-[var(--color-muted)]">{item.region || item.countryCode || 'No location detail'}</p></td><td className="max-w-xs px-4 py-4"><p className="font-bold">{item.distinctUsersCount} attributed</p><p className="mt-1 truncate text-[10px] text-[var(--color-muted)]" title={item.users.map((user) => user.email).join(', ')}>{item.users.map((user) => user.email).join(', ') || 'Unauthenticated only'}</p></td><td className="px-4 py-4 font-black">{formatNumber(item.requestCount)}</td><td className="px-4 py-4"><p className="font-black text-rose-200">{formatNumber(item.errorCount)}</p><p className="mt-1 text-[9px] text-[var(--color-muted)]">401 {item.status401Count} · 403 {item.status403Count} · 404 {item.status404Count} · 429 {item.status429Count} · 5xx {item.status5xxCount}</p></td><td className="px-4 py-4"><p className="text-lg font-black">{item.riskScore}</p><p className="mt-1 text-[10px] text-[var(--color-muted)]">{item.riskReason}</p></td><td className="px-4 py-4"><span className={`rounded-full border px-2.5 py-1 text-[9px] font-black uppercase ${riskStyles[item.riskLevel]}`}>{item.riskLevel}</span></td><td className="whitespace-nowrap px-5 py-4 text-[var(--color-muted)]">{formatDate(item.lastSeenAt)}</td></tr>)}</tbody></table></div>}<div className="flex items-center justify-between border-t border-[var(--color-border)] px-5 py-4 text-xs text-[var(--color-muted)]"><span>Showing {data.pagination.from ?? 0}–{data.pagination.to ?? 0} of {data.pagination.total}</span><div className="flex items-center gap-2"><button disabled={!data.pagination.previousPageUrl} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Previous</button><span>Page {data.pagination.currentPage} of {data.pagination.lastPage}</span><button disabled={!data.pagination.nextPageUrl} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 disabled:opacity-40">Next</button></div></div></section>
        <p className="text-right text-[10px] text-[var(--color-muted)]">{data.metadata.geolocation} Generated {formatDate(data.metadata.generatedAt)}.</p>
      </> : null}
    </div>
  );
};
