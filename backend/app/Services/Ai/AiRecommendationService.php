<?php

namespace App\Services\Ai;

use App\Exceptions\AiRecommendationException;
use App\Models\Audit;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiRecommendationService
{
    /**
     * @return array{provider: string, prompt_summary: string, generated_text: string}
     */
    public function generate(Audit $audit): array
    {
        $provider = (string) config('services.ai.provider');
        $baseUrl = (string) config('services.ai.base_url');
        $chatEndpoint = (string) config('services.ai.chat_endpoint');
        $model = (string) config('services.ai.model');
        $apiKey = (string) config('services.ai.api_key');

        if (in_array('', [$provider, $baseUrl, $chatEndpoint, $model, $apiKey], true)) {
            throw new AiRecommendationException();
        }

        $audit->loadMissing(['domain', 'issues']);
        $prompt = $this->buildPrompt($audit);
        $promptSummary = sprintf(
            'SEO recommendations for audit #%d with %d detected issue(s).',
            $audit->id,
            $audit->issues->count(),
        );

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($apiKey)
                ->post(rtrim($baseUrl, '/').'/'.ltrim($chatEndpoint, '/'), [
                    'model' => $model,
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
        } catch (Throwable) {
            throw new AiRecommendationException();
        }

        if (! $response->successful()) {
            throw new AiRecommendationException();
        }

        $generatedText = $response->json('choices.0.message.content');

        if (! is_string($generatedText) || trim($generatedText) === '') {
            throw new AiRecommendationException();
        }

        return [
            'provider' => $provider,
            'prompt_summary' => $promptSummary,
            'generated_text' => trim($generatedText),
        ];
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
