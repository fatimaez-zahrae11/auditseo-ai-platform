<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebAnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_view_can_be_recorded_without_authentication_and_identifiers_are_hashed(): void
    {
        $visitorId = 'f61d1df0-9d0a-41fc-9aca-3a190e820bad';
        $sessionId = '26546dd5-e3b3-40d1-ad06-f690237bd340';

        $this->postJson('/api/analytics/page-view', [
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'path' => 'https://auditseo.test/dashboard?token=must-not-be-stored&email=user@example.com',
            'page_title' => " Dashboard\nOverview ",
            'referrer' => 'https://search.example.test/results?q=private',
        ])->assertNoContent();

        $event = WebAnalyticsEvent::query()->sole();
        $this->assertNull($event->user_id);
        $this->assertSame('/dashboard', $event->path);
        $this->assertSame('Dashboard Overview', $event->page_title);
        $this->assertSame('search.example.test', $event->referrer_host);
        $this->assertSame(WebAnalyticsEvent::TYPE_PAGE_VIEW, $event->event_type);
        $this->assertNotSame($visitorId, $event->visitor_id_hash);
        $this->assertNotSame($sessionId, $event->session_id_hash);
        $this->assertSame(64, strlen((string) $event->visitor_id_hash));
        $this->assertSame(64, strlen((string) $event->session_id_hash));

        $stored = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($visitorId, $stored);
        $this->assertStringNotContainsString($sessionId, $stored);
        $this->assertStringNotContainsString('must-not-be-stored', $stored);
        $this->assertStringNotContainsString('user@example.com', $stored);
        $this->assertStringNotContainsString('/results?q=private', $stored);
        $this->assertDatabaseCount('access_logs', 0);
    }

    public function test_authorization_header_is_ignored_and_tracking_remains_anonymous(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web-analytics-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/analytics/page-view', $this->validPayload())
            ->assertNoContent();

        $this->assertDatabaseHas('web_analytics_events', [
            'user_id' => null,
            'path' => '/dashboard',
            'event_type' => WebAnalyticsEvent::TYPE_PAGE_VIEW,
        ]);
        $this->assertDatabaseCount('access_logs', 0);
    }

    public function test_invalid_bearer_token_is_treated_as_anonymous_tracking(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->postJson('/api/analytics/page-view', $this->validPayload())
            ->assertNoContent();

        $this->assertDatabaseHas('web_analytics_events', [
            'user_id' => null,
            'path' => '/dashboard',
        ]);
    }

    public function test_page_view_payload_is_validated(): void
    {
        $this->postJson('/api/analytics/page-view', [
            'visitor_id' => 'contains spaces',
            'session_id' => '',
            'path' => '',
            'referrer' => 'javascript:alert(1)',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'visitor_id',
                'session_id',
                'path',
                'referrer',
            ]);
    }

    public function test_page_view_payload_lengths_are_strictly_bounded(): void
    {
        $this->postJson('/api/analytics/page-view', [
            'visitor_id' => str_repeat('a', 65),
            'session_id' => str_repeat('b', 65),
            'path' => '/'.str_repeat('p', 512),
            'page_title' => str_repeat('t', 201),
            'referrer' => 'https://example.test/'.str_repeat('r', 1004),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'visitor_id',
                'session_id',
                'path',
                'page_title',
                'referrer',
            ]);

        $this->assertDatabaseCount('web_analytics_events', 0);
    }

    public function test_page_view_ingestion_is_capped_per_visitor(): void
    {
        foreach (range(1, 30) as $attempt) {
            $this->postJson('/api/analytics/page-view', $this->validPayload())
                ->assertNoContent();
        }

        $this->postJson('/api/analytics/page-view', $this->validPayload())
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too many requests.']);

        $this->assertDatabaseCount('web_analytics_events', 30);
        $this->assertDatabaseCount('access_logs', 0);
    }

    public function test_admin_web_traffic_endpoint_enforces_authentication_and_admin_role(): void
    {
        $this->getJson('/api/admin/analytics/web-traffic')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/analytics/web-traffic')->assertForbidden();

        Sanctum::actingAs($this->createAdmin());
        $this->getJson('/api/admin/analytics/web-traffic')->assertOk();
    }

    public function test_admin_web_traffic_uses_only_real_rows_for_aggregates_bounce_rate_and_top_pages(): void
    {
        $this->travelTo('2026-08-31 12:30:00');
        $admin = $this->createAdmin();

        $this->createEvent('visitor-a', 'session-a', '/dashboard', '2026-08-31 09:00:00');
        $this->createEvent('visitor-b', 'session-b', '/dashboard', '2026-08-31 10:00:00');
        $this->createEvent('visitor-b', 'session-b', '/audits', '2026-08-31 11:00:00');
        $this->createEvent('outside-period', 'outside-session', '/old', '2026-07-01 10:00:00');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/analytics/web-traffic?period=24h');

        $response
            ->assertOk()
            ->assertJsonCount(24, 'series')
            ->assertJsonPath('totals.page_views', 3)
            ->assertJsonPath('totals.tracked_visitors', 2)
            ->assertJsonPath('totals.sessions', 2)
            ->assertJsonPath('totals.bounce_rate', 50)
            ->assertJsonPath('top_pages.0.path', '/dashboard')
            ->assertJsonPath('top_pages.0.page_views', 2)
            ->assertJsonPath('top_pages.0.tracked_visitors', 2)
            ->assertJsonPath('top_pages.0.sessions', 2)
            ->assertJsonPath('metadata.period', '24h')
            ->assertJsonPath('metadata.granularity', 'hour')
            ->assertJsonPath('metadata.source', 'web_analytics_events');

        $content = $response->getContent();
        foreach (['visitor_id', 'session_id', 'visitor-a', 'session-a', 'visitor-b', 'session-b'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $content);
        }
    }

    public function test_empty_web_traffic_period_returns_real_zero_filled_buckets(): void
    {
        $this->travelTo('2026-08-31 12:30:00');
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/analytics/web-traffic?period=30d');

        $response
            ->assertOk()
            ->assertJsonCount(30, 'series')
            ->assertJsonPath('totals.page_views', 0)
            ->assertJsonPath('totals.tracked_visitors', 0)
            ->assertJsonPath('totals.sessions', 0)
            ->assertJsonPath('totals.bounce_rate', null)
            ->assertJsonCount(0, 'top_pages');
        $this->assertTrue(collect($response->json('series'))->every(
            fn (array $point): bool => $point['page_views'] === 0
                && $point['tracked_visitors'] === 0
                && $point['sessions'] === 0
                && $point['bounce_rate'] === null,
        ));
    }

    public function test_web_traffic_filters_are_validated(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->getJson('/api/admin/analytics/web-traffic?period=90d')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
        $this->getJson('/api/admin/analytics/web-traffic?granularity=minute')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('granularity');
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(): array
    {
        return [
            'visitor_id' => 'f61d1df0-9d0a-41fc-9aca-3a190e820bad',
            'session_id' => '26546dd5-e3b3-40d1-ad06-f690237bd340',
            'path' => '/dashboard?private=value',
            'page_title' => 'Dashboard',
            'referrer' => 'https://auditseo.test/login?source=private',
        ];
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        return $admin;
    }

    private function createEvent(
        string $visitorHash,
        string $sessionHash,
        string $path,
        string $createdAt,
    ): WebAnalyticsEvent {
        $event = WebAnalyticsEvent::query()->create([
            'visitor_id_hash' => hash('sha256', $visitorHash),
            'session_id_hash' => hash('sha256', $sessionHash),
            'path' => $path,
            'event_type' => WebAnalyticsEvent::TYPE_PAGE_VIEW,
        ]);
        $event->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $event->refresh();
    }
}
