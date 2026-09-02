<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\ActionLog;
use App\Models\AdminActionLog;
use App\Models\IpGeolocation;
use App\Models\User;
use App\Models\WebAnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_retention_prunes_expired_security_and_analytics_records_only(): void
    {
        $this->travelTo('2026-09-02 12:00:00');
        config([
            'retention.access_logs_days' => 10,
            'retention.action_logs_days' => 10,
            'retention.admin_action_logs_days' => 10,
            'retention.web_analytics_events_days' => 10,
            'retention.ip_geolocations_days' => 10,
        ]);
        $user = User::factory()->create();

        [$oldAccess, $freshAccess] = $this->accessLogs($user);
        [$oldAction, $freshAction] = $this->actionLogs($user);
        [$oldAdminAction, $freshAdminAction] = $this->adminActionLogs($user);
        [$oldAnalytics, $freshAnalytics] = $this->analyticsEvents();
        [$oldIp, $freshIp] = $this->ipGeolocations();

        $exitCode = Artisan::call('model:prune', [
            '--model' => [
                AccessLog::class,
                ActionLog::class,
                AdminActionLog::class,
                WebAnalyticsEvent::class,
                IpGeolocation::class,
            ],
        ]);

        $this->assertSame(0, $exitCode);
        foreach ([$oldAccess, $oldAction, $oldAdminAction, $oldAnalytics, $oldIp] as $expired) {
            $this->assertDatabaseMissing($expired->getTable(), ['id' => $expired->id]);
        }
        foreach ([$freshAccess, $freshAction, $freshAdminAction, $freshAnalytics, $freshIp] as $retained) {
            $this->assertDatabaseHas($retained->getTable(), ['id' => $retained->id]);
        }
    }

    public function test_failed_job_retention_command_removes_only_expired_rows(): void
    {
        $this->travelTo('2026-09-02 12:00:00');
        $oldId = DB::table('failed_jobs')->insertGetId($this->failedJob(now()->subDays(31)));
        $freshId = DB::table('failed_jobs')->insertGetId($this->failedJob(now()->subDays(29)));

        $this->assertSame(0, Artisan::call('queue:prune-failed', ['--hours' => 720]));
        $this->assertDatabaseMissing('failed_jobs', ['id' => $oldId]);
        $this->assertDatabaseHas('failed_jobs', ['id' => $freshId]);
    }

    /** @return array{AccessLog, AccessLog} */
    private function accessLogs(User $user): array
    {
        $attributes = [
            'user_id' => $user->id,
            'method' => 'GET',
            'route' => '/api/test',
            'status_code' => 200,
        ];

        return [
            AccessLog::query()->create([...$attributes, 'created_at' => now()->subDays(11)]),
            AccessLog::query()->create([...$attributes, 'created_at' => now()->subDays(9)]),
        ];
    }

    /** @return array{ActionLog, ActionLog} */
    private function actionLogs(User $user): array
    {
        $attributes = [
            'actor_user_id' => $user->id,
            'actor_role' => User::ROLE_USER,
            'action' => ActionLog::ACTION_USER_LOGGED_IN,
            'status' => ActionLog::STATUS_SUCCESS,
        ];

        $old = ActionLog::query()->create($attributes);
        $fresh = ActionLog::query()->create($attributes);
        ActionLog::withoutTimestamps(fn () => $old->forceFill([
            'created_at' => now()->subDays(11),
            'updated_at' => now()->subDays(11),
        ])->save());
        ActionLog::withoutTimestamps(fn () => $fresh->forceFill([
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ])->save());

        return [$old, $fresh];
    }

    /** @return array{AdminActionLog, AdminActionLog} */
    private function adminActionLogs(User $user): array
    {
        $attributes = [
            'admin_user_id' => $user->id,
            'action' => AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED,
        ];

        return [
            AdminActionLog::query()->create([...$attributes, 'created_at' => now()->subDays(11)]),
            AdminActionLog::query()->create([...$attributes, 'created_at' => now()->subDays(9)]),
        ];
    }

    /** @return array{WebAnalyticsEvent, WebAnalyticsEvent} */
    private function analyticsEvents(): array
    {
        return [
            $this->analyticsEvent('old', now()->subDays(11)),
            $this->analyticsEvent('fresh', now()->subDays(9)),
        ];
    }

    private function analyticsEvent(string $identifier, mixed $createdAt): WebAnalyticsEvent
    {
        $event = WebAnalyticsEvent::query()->create([
            'visitor_id_hash' => hash('sha256', 'visitor-'.$identifier),
            'session_id_hash' => hash('sha256', 'session-'.$identifier),
            'path' => '/'.$identifier,
            'event_type' => WebAnalyticsEvent::TYPE_PAGE_VIEW,
        ]);
        WebAnalyticsEvent::withoutTimestamps(fn () => $event->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save());

        return $event;
    }

    /** @return array{IpGeolocation, IpGeolocation} */
    private function ipGeolocations(): array
    {
        return [
            $this->ipGeolocation('old', now()->subDays(11)),
            $this->ipGeolocation('fresh', now()->subDays(9)),
        ];
    }

    private function ipGeolocation(string $identifier, mixed $updatedAt): IpGeolocation
    {
        $location = IpGeolocation::query()->create([
            'ip_hash' => hash('sha256', $identifier),
            'ip_masked' => '198.51.100.0/24',
        ]);
        IpGeolocation::withoutTimestamps(fn () => $location->forceFill([
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ])->save());

        return $location;
    }

    /** @return array<string, mixed> */
    private function failedJob(mixed $failedAt): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Sanitized test exception.',
            'failed_at' => $failedAt,
        ];
    }
}
