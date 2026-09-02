import type {
  AdminIpIntelligenceData,
  AdminIpRiskLevel,
  AdminTrafficPeriod,
  AuditPagination,
} from '../types';
import { apiRequest, getAuthGeneration, subscribeToAuthState } from './apiClient';

type UnknownRecord = Record<string, unknown>;
const asRecord = (value: unknown): UnknownRecord => typeof value === 'object' && value !== null ? value as UnknownRecord : {};
const asArray = (value: unknown): unknown[] => Array.isArray(value) ? value : [];
const asString = (value: unknown, fallback = '') => typeof value === 'string' ? value : fallback;
const optionalString = (value: unknown) => typeof value === 'string' && value ? value : null;
const asNumber = (value: unknown) => {
  const number = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(number) ? number : 0;
};
const optionalNumber = (value: unknown) => value === null || value === undefined ? null : asNumber(value);
const riskLevel = (value: unknown): AdminIpRiskLevel => value === 'critical' || value === 'high' || value === 'medium' ? value : 'low';
const period = (value: unknown): AdminTrafficPeriod => value === '7d' || value === '30d' ? value : '24h';
const pagination = (value: unknown): AuditPagination => {
  const data = asRecord(value);
  return {
    currentPage: asNumber(data.current_page) || 1,
    lastPage: asNumber(data.last_page) || 1,
    perPage: asNumber(data.per_page) || 20,
    total: asNumber(data.total),
    from: data.from === null || data.from === undefined ? null : asNumber(data.from),
    to: data.to === null || data.to === undefined ? null : asNumber(data.to),
    previousPageUrl: optionalString(data.previous_page_url),
    nextPageUrl: optionalString(data.next_page_url),
  };
};
const queryString = (params: Record<string, string | number | undefined>) => {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value));
  });
  const serialized = query.toString();
  return serialized ? `?${serialized}` : '';
};
const inFlightRequests = new Map<string, Promise<unknown>>();

subscribeToAuthState(() => inFlightRequests.clear());

const dedupedGet = (path: string) => {
  const key = `${getAuthGeneration()}:${path}`;
  const existing = inFlightRequests.get(key);
  if (existing) return existing;
  const request = apiRequest<unknown>(path, {}, { auth: 'required' }).finally(() => {
    if (inFlightRequests.get(key) === request) inFlightRequests.delete(key);
  });
  inFlightRequests.set(key, request);
  return request;
};

export interface AdminIpIntelligenceFilters {
  period?: AdminTrafficPeriod;
  risk?: AdminIpRiskLevel;
  country?: string;
  userId?: string;
  page?: number;
  perPage?: number;
}

export const adminSecurityService = {
  ipIntelligence: async (filters: AdminIpIntelligenceFilters = {}): Promise<AdminIpIntelligenceData> => {
    const data = asRecord(await dedupedGet(`/admin/security/ip-intelligence${queryString({
      period: filters.period ?? '24h',
      risk: filters.risk,
      country: filters.country?.trim() || undefined,
      user_id: filters.userId,
      page: filters.page ?? 1,
      per_page: filters.perPage ?? 20,
    })}`));
    const summary = asRecord(data.summary);
    const metadata = asRecord(data.metadata);
    return {
      summary: {
        critical: asNumber(summary.critical),
        high: asNumber(summary.high),
        medium: asNumber(summary.medium),
        low: asNumber(summary.low),
        uniqueIps: asNumber(summary.unique_ips),
        countriesCount: asNumber(summary.countries_count),
        requestsCount: asNumber(summary.requests_count),
        errorsCount: asNumber(summary.errors_count),
      },
      topAddressesHeatmap: asArray(data.top_addresses_heatmap).map((value) => {
        const item = asRecord(value);
        return { ipMasked: asString(item.ip_masked), label: asString(item.label), requestCount: asNumber(item.request_count), errorCount: asNumber(item.error_count), riskScore: asNumber(item.risk_score), riskLevel: riskLevel(item.risk_level) };
      }),
      mapPoints: asArray(data.map_points).map((value) => {
        const item = asRecord(value);
        return { countryCode: optionalString(item.country_code), countryName: asString(item.country_name, 'Unknown'), city: optionalString(item.city), latitude: asNumber(item.latitude), longitude: asNumber(item.longitude), requestCount: asNumber(item.request_count), errorCount: asNumber(item.error_count), riskLevel: riskLevel(item.risk_level) };
      }),
      externalExposure: asArray(data.external_exposure).map((value) => {
        const item = asRecord(value);
        return { title: asString(item.title), ipMasked: asString(item.ip_masked), riskLevel: riskLevel(item.risk_level), reason: asString(item.reason), requestCount: asNumber(item.request_count), lastSeenAt: optionalString(item.last_seen_at) };
      }),
      results: asArray(data.results).map((value) => {
        const item = asRecord(value);
        return {
          ipMasked: asString(item.ip_masked), countryCode: optionalString(item.country_code), countryName: asString(item.country_name, 'Unknown'), region: optionalString(item.region), city: optionalString(item.city), latitude: optionalNumber(item.latitude), longitude: optionalNumber(item.longitude),
          requestCount: asNumber(item.request_count), errorCount: asNumber(item.error_count), status401Count: asNumber(item.status_401_count), status403Count: asNumber(item.status_403_count), status404Count: asNumber(item.status_404_count), status429Count: asNumber(item.status_429_count), status5xxCount: asNumber(item.status_5xx_count), distinctRoutesCount: asNumber(item.distinct_routes_count), distinctUsersCount: asNumber(item.distinct_users_count),
          users: asArray(item.users).map((userValue) => { const user = asRecord(userValue); return { id: String(user.id ?? ''), email: asString(user.email) }; }),
          riskLevel: riskLevel(item.risk_level), riskScore: asNumber(item.risk_score), riskReason: asString(item.risk_reason), lastSeenAt: optionalString(item.last_seen_at),
        };
      }),
      pagination: pagination(data.pagination),
      metadata: { period: period(metadata.period), from: optionalString(metadata.from), to: optionalString(metadata.to), generatedAt: optionalString(metadata.generated_at), ipDisplay: asString(metadata.ip_display), geolocation: asString(metadata.geolocation) },
    };
  },
};
