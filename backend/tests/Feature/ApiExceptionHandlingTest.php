<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiExceptionHandlingTest extends TestCase
{
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

        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('/var/www/app.php', $content);
        $this->assertStringNotContainsString('secret-api-key', $content);
        $this->assertStringNotContainsString('trace', $content);
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
}
