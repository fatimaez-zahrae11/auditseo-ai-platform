<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiExceptionHandlingTest extends TestCase
{
    public function test_unknown_api_endpoints_return_sanitized_404_json(): void
    {
        $response = $this->getJson('/api/unknown-endpoint-with-secret-marker');

        $response
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');

        $this->assertSensitiveDetailsAreHidden($response->getContent());
        $this->assertStringNotContainsString('unknown-endpoint-with-secret-marker', $response->getContent());
    }

    public function test_unsupported_api_methods_return_sanitized_405_json(): void
    {
        Route::get('/api/test-method-not-allowed', fn () => response()->noContent());

        $response = $this->postJson('/api/test-method-not-allowed');

        $response
            ->assertStatus(405)
            ->assertExactJson([
                'message' => 'Method not allowed.',
            ])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');

        $this->assertSensitiveDetailsAreHidden($response->getContent());
        $this->assertStringNotContainsString('test-method-not-allowed', $response->getContent());
    }

    public function test_safe_http_exceptions_preserve_their_status_without_exposing_details(): void
    {
        $expectedMessages = [
            400 => 'Bad request.',
            409 => 'Conflict.',
            413 => 'Request entity too large.',
            415 => 'Unsupported media type.',
        ];

        foreach ($expectedMessages as $status => $message) {
            Route::get("/api/test-http-exception-{$status}", function () use ($status): never {
                throw new HttpException(
                    $status,
                    'SQLSTATE at /var/www/app.php with password=secret-api-key',
                );
            });

            $response = $this->getJson("/api/test-http-exception-{$status}");

            $response
                ->assertStatus($status)
                ->assertExactJson([
                    'message' => $message,
                ])
                ->assertJsonMissingPath('exception')
                ->assertJsonMissingPath('trace');

            $this->assertSensitiveDetailsAreHidden($response->getContent());
        }
    }

    public function test_unhandled_api_exceptions_return_sanitized_json(): void
    {
        Route::get('/api/test-unhandled-exception', function (): never {
            throw new RuntimeException(
                'SQLSTATE connection failed at /var/www/app.php with password=secret-api-key',
            );
        });

        $response = $this->getJson('/api/test-unhandled-exception');

        $response
            ->assertInternalServerError()
            ->assertExactJson([
                'message' => 'Internal server error.',
            ]);

        $content = $response->getContent();

        $this->assertSensitiveDetailsAreHidden($content);
    }

    public function test_validation_errors_still_return_422_json(): void
    {
        Route::post('/api/test-validation-exception', function (Request $request) {
            $request->validate([
                'email' => ['required', 'email'],
            ]);

            return response()->noContent();
        });

        $this->postJson('/api/test-validation-exception', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    private function assertSensitiveDetailsAreHidden(string $content): void
    {
        foreach ([
            'SQLSTATE',
            '/var/www/app.php',
            'password',
            'secret-api-key',
            'exception',
            'trace',
            'stack',
        ] as $sensitiveDetail) {
            $this->assertStringNotContainsString($sensitiveDetail, $content);
        }
    }
}
