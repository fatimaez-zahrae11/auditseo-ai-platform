<?php

namespace App\Services\Ai;

use App\Exceptions\AiRecommendationException;
use App\Models\ApiUsageLog;
use App\Models\Audit;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class AiRecommendationService
{
    private const DOWNLOAD_CHUNK_BYTES = 8192;

    private const DEFAULT_MAX_OUTPUT_TOKENS = 2048;

    private const DEFAULT_MAX_RESPONSE_BYTES = 1_048_576;

    private const DEFAULT_MAX_GENERATED_TEXT_CHARS = 20_000;

    private const ABSOLUTE_MAX_OUTPUT_TOKENS = 32_768;

    private const ABSOLUTE_MAX_RESPONSE_BYTES = 10_000_000;

    private const ABSOLUTE_MAX_GENERATED_TEXT_CHARS = 200_000;

    /**
     * @return array{provider: string, prompt_summary: string, generated_text: string}
     */
    public function generate(Audit $audit): array
    {
        $provider = (string) config('services.ai.provider');
        $baseUrl = (string) config('services.ai.base_url');
        $allowedHosts = config('services.ai.allowed_hosts', []);
        $chatEndpoint = (string) config('services.ai.chat_endpoint');
        $model = (string) config('services.ai.model');
        $apiKey = (string) config('services.ai.api_key');
        $maxOutputTokens = $this->configuredLimit(
            'services.ai.max_output_tokens',
            self::DEFAULT_MAX_OUTPUT_TOKENS,
            self::ABSOLUTE_MAX_OUTPUT_TOKENS,
        );
        $maxResponseBytes = $this->configuredLimit(
            'services.ai.max_response_bytes',
            self::DEFAULT_MAX_RESPONSE_BYTES,
            self::ABSOLUTE_MAX_RESPONSE_BYTES,
        );
        $maxGeneratedTextChars = $this->configuredLimit(
            'services.ai.max_generated_text_chars',
            self::DEFAULT_MAX_GENERATED_TEXT_CHARS,
            self::ABSOLUTE_MAX_GENERATED_TEXT_CHARS,
        );

        if (in_array('', [$provider, $baseUrl, $chatEndpoint, $model, $apiKey], true)) {
            throw new AiRecommendationException;
        }

        $providerUrl = $this->validatedProviderUrl($baseUrl, $chatEndpoint, $allowedHosts);

        $audit->loadMissing(['domain', 'issues']);
        $prompt = $this->buildPrompt($audit);
        $promptSummary = sprintf(
            'SEO recommendations for audit #%d with %d detected issue(s).',
            $audit->id,
            $audit->issues->count(),
        );

        $response = null;

        try {
            set_time_limit(180);
            $response = Http::connectTimeout(10)
                ->timeout(120)
                ->acceptJson()
                ->withToken($apiKey)
                ->withOptions(['allow_redirects' => false])
                ->setHandler(new BoundedAiCurlHandler(
                    $maxResponseBytes,
                    fn (ResponseInterface $response) => $this->rejectOversizedContentLength(
                        $response,
                        $maxResponseBytes,
                    ),
                ))
                ->post($providerUrl, [
                    'model' => $model,
                    'max_tokens' => $maxOutputTokens,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an SEO specialist. Provide clear, prioritized, actionable recommendations based only on the supplied audit data.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);
            $responseBody = $this->readBoundedResponseBody($response, $maxResponseBytes);
        } catch (Throwable) {
            $this->logUsage(
                userId: $audit->domain?->user_id,
                provider: $provider,
                status: 'failed',
                statusCode: $response?->status(),
                errorMessage: 'External AI request failed.',
            );

            throw new AiRecommendationException;
        }

        if (! $response->successful()) {
            $this->logUsage(
                userId: $audit->domain?->user_id,
                provider: $provider,
                status: 'failed',
                statusCode: $response->status(),
                errorMessage: 'External AI request failed.',
            );

            throw new AiRecommendationException;
        }

        try {
            $responseData = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->rejectInvalidResponse($audit, $provider, $response->status());
        }

        $generatedText = data_get($responseData, 'choices.0.message.content');

        if (! is_string($generatedText)
            || trim($generatedText) === ''
            || Str::length(trim($generatedText)) > $maxGeneratedTextChars) {
            $this->rejectInvalidResponse($audit, $provider, $response->status());
        }

        $this->logUsage(
            userId: $audit->domain?->user_id,
            provider: $provider,
            status: 'success',
            statusCode: $response->status(),
        );

        return [
            'provider' => $provider,
            'prompt_summary' => $promptSummary,
            'generated_text' => trim($generatedText),
        ];
    }

    private function validatedProviderUrl(
        string $baseUrl,
        string $chatEndpoint,
        mixed $configuredAllowedHosts,
    ): string {
        $baseUrl = trim($baseUrl);
        $parts = parse_url($baseUrl);

        if ($parts === false
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || trim((string) $parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            throw new AiRecommendationException;
        }

        if (! is_array($configuredAllowedHosts) || $configuredAllowedHosts === []) {
            throw new AiRecommendationException;
        }

        $allowedHosts = [];
        foreach ($configuredAllowedHosts as $allowedHost) {
            if (! is_string($allowedHost)) {
                throw new AiRecommendationException;
            }

            $allowedHost = strtolower(trim($allowedHost));
            if ($allowedHost === ''
                || str_contains($allowedHost, '*')
                || filter_var($allowedHost, FILTER_VALIDATE_IP) === false
                    && filter_var($allowedHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new AiRecommendationException;
            }

            $allowedHosts[] = $allowedHost;
        }

        $providerHost = strtolower((string) $parts['host']);
        if (! in_array($providerHost, $allowedHosts, true)) {
            throw new AiRecommendationException;
        }

        $providerUrl = rtrim($baseUrl, '/').'/'.ltrim(trim($chatEndpoint), '/');
        $providerParts = parse_url($providerUrl);

        if ($providerParts === false
            || filter_var($providerUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) ($providerParts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($providerParts['host'] ?? '')) !== $providerHost
            || array_key_exists('user', $providerParts)
            || array_key_exists('pass', $providerParts)
            || array_key_exists('fragment', $providerParts)) {
            throw new AiRecommendationException;
        }

        return $providerUrl;
    }

    private function configuredLimit(string $key, int $default, int $absoluteMax): int
    {
        $value = (int) config($key, $default);

        if ($value < 1 || $value > $absoluteMax) {
            throw new AiRecommendationException;
        }

        return $value;
    }

    private function readBoundedResponseBody(Response $response, int $maxBytes): string
    {
        try {
            $psrResponse = $response->toPsrResponse();
            $this->rejectOversizedContentLength($psrResponse, $maxBytes);

            $source = $psrResponse->getBody();
            $temporary = fopen("php://temp/maxmemory:{$maxBytes}", 'w+b');
            if ($temporary === false) {
                throw new RuntimeException('Unable to buffer the AI response.');
            }

            try {
                $downloadedBytes = 0;

                while (! $source->eof()) {
                    $remainingBytes = $maxBytes - $downloadedBytes;
                    $chunk = $source->read(min(self::DOWNLOAD_CHUNK_BYTES, $remainingBytes + 1));

                    if ($chunk === '') {
                        if ($source->eof()) {
                            break;
                        }

                        throw new RuntimeException('Unable to read the AI response.');
                    }

                    $downloadedBytes += strlen($chunk);
                    if ($downloadedBytes > $maxBytes) {
                        throw new RuntimeException('The AI response exceeded the size limit.');
                    }

                    if (fwrite($temporary, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Unable to buffer the AI response.');
                    }
                }

                rewind($temporary);
                $body = stream_get_contents($temporary);
                if ($body === false) {
                    throw new RuntimeException('Unable to read the buffered AI response.');
                }

                return $body;
            } finally {
                fclose($temporary);
            }
        } finally {
            $response->close();
        }
    }

    private function rejectOversizedContentLength(ResponseInterface $response, int $maxBytes): void
    {
        foreach (['Content-Length', 'X-Encoded-Content-Length'] as $headerName) {
            foreach ($response->getHeader($headerName) as $header) {
                foreach (explode(',', $header) as $value) {
                    $value = ltrim(trim($value), '0');
                    $value = $value === '' ? '0' : $value;
                    $limit = (string) $maxBytes;

                    if (ctype_digit($value)
                        && (strlen($value) > strlen($limit)
                            || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0))) {
                        throw new RuntimeException('The AI response exceeded the size limit.');
                    }
                }
            }
        }
    }

    private function rejectInvalidResponse(Audit $audit, string $provider, int $statusCode): never
    {
        $this->logUsage(
            userId: $audit->domain?->user_id,
            provider: $provider,
            status: 'failed',
            statusCode: $statusCode,
            errorMessage: 'External AI response was invalid.',
        );

        throw new AiRecommendationException;
    }

    private function logUsage(
        ?int $userId,
        string $provider,
        string $status,
        ?int $statusCode = null,
        ?string $errorMessage = null,
    ): void {
        try {
            ApiUsageLog::create([
                'user_id' => $userId,
                'provider' => $provider,
                'status' => $status,
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable) {
            Log::warning('Unable to persist AI API usage log.', [
                'provider' => $provider,
                'status' => $status,
                'status_code' => $statusCode,
            ]);
        }
    }

    private function buildPrompt(Audit $audit): string
    {
        $auditData = [
            'url' => $audit->domain?->url,
            'scores' => [
                'global' => $audit->global_score,
                'technical' => $audit->technical_score,
                'content' => $audit->content_score,
                'links' => $audit->links_score,
                'performance' => $audit->performance_score,
            ],
            'raw_data' => $audit->raw_data,
            'issues' => $audit->issues->map(fn ($issue) => [
                'category' => $issue->category,
                'title' => $issue->title,
                'severity' => $issue->severity,
                'description' => $issue->description,
                'recommendation' => $issue->recommendation,
            ])->values()->all(),
        ];

        return "Analyze this SEO audit and return prioritized recommendations with concrete fixes:\n"
            .json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
