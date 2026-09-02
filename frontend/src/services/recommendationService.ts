import type { RecommendationPagination, UserAiRecommendation } from '../types';
import { apiRequest, getAuthGeneration, subscribeToAuthState } from './apiClient';

interface BackendRecommendation {
  id?: unknown;
  audit_id?: unknown;
  generated_text?: unknown;
  created_at?: unknown;
  updated_at?: unknown;
}

interface BackendPagination {
  current_page?: unknown;
  last_page?: unknown;
  per_page?: unknown;
  total?: unknown;
  from?: unknown;
  to?: unknown;
  previous_page_url?: unknown;
  next_page_url?: unknown;
}

interface RecommendationListResponse {
  recommendations?: BackendRecommendation[];
  pagination?: BackendPagination;
}

interface GenerateRecommendationResponse {
  message: string;
  recommendation: BackendRecommendation;
}

interface RecommendationListData {
  recommendations: UserAiRecommendation[];
  pagination: RecommendationPagination;
}

const numberValue = (value: unknown) => {
  const parsed = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

const optionalString = (value: unknown) => typeof value === 'string' && value ? value : undefined;

const mapRecommendation = (value: BackendRecommendation): UserAiRecommendation => ({
  id: String(value.id ?? ''),
  auditId: String(value.audit_id ?? ''),
  generatedText: typeof value.generated_text === 'string' ? value.generated_text : '',
  createdAt: optionalString(value.created_at) || new Date(0).toISOString(),
  updatedAt: optionalString(value.updated_at),
});

const mapPagination = (value?: BackendPagination): RecommendationPagination => ({
  currentPage: numberValue(value?.current_page) || 1,
  lastPage: numberValue(value?.last_page) || 1,
  perPage: numberValue(value?.per_page) || 20,
  total: numberValue(value?.total),
  from: value?.from === null || value?.from === undefined ? null : numberValue(value.from),
  to: value?.to === null || value?.to === undefined ? null : numberValue(value.to),
  previousPageUrl: optionalString(value?.previous_page_url) || null,
  nextPageUrl: optionalString(value?.next_page_url) || null,
});

const listRequests = new Map<string, Promise<RecommendationListData>>();
const generationRequests = new Map<string, Promise<{
  message: string;
  recommendation: UserAiRecommendation;
}>>();

subscribeToAuthState(() => {
  listRequests.clear();
  generationRequests.clear();
});

const requestSession = () => getAuthGeneration();

const list = (auditId: string, page = 1) => {
  const requestKey = `${requestSession()}:${auditId}:${page}`;
  const inFlightRequest = listRequests.get(requestKey);
  if (inFlightRequest) return inFlightRequest;

  const request = apiRequest<RecommendationListResponse>(
    `/audits/${encodeURIComponent(auditId)}/recommendations?page=${page}`,
    {},
    { auth: 'required' },
  )
    .then((data): RecommendationListData => ({
      recommendations: Array.isArray(data.recommendations)
        ? data.recommendations.map(mapRecommendation)
        : [],
      pagination: mapPagination(data.pagination),
    }))
    .finally(() => {
      if (listRequests.get(requestKey) === request) listRequests.delete(requestKey);
    });

  listRequests.set(requestKey, request);
  return request;
};

const generate = (auditId: string) => {
  const requestKey = `${requestSession()}:${auditId}`;
  const inFlightRequest = generationRequests.get(requestKey);
  if (inFlightRequest) return inFlightRequest;

  const request = apiRequest<GenerateRecommendationResponse>(
    `/audits/${encodeURIComponent(auditId)}/recommendations`,
    { method: 'POST' },
    { auth: 'required' },
  )
    .then((data) => ({
      message: data.message,
      recommendation: mapRecommendation(data.recommendation),
    }))
    .finally(() => {
      if (generationRequests.get(requestKey) === request) generationRequests.delete(requestKey);
    });

  generationRequests.set(requestKey, request);
  return request;
};

export const recommendationService = {
  list,
  generate,
};
