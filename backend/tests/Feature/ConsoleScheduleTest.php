<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ConsoleScheduleTest extends TestCase
{
    public function test_expired_sanctum_token_pruning_is_scheduled_daily(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                $event->command ?? '',
                'sanctum:prune-expired --hours=24',
            ));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_model_and_failed_job_retention_pruning_are_scheduled_daily(): void
    {
        config(['retention.failed_jobs_hours' => 720]);
        $events = collect($this->app->make(Schedule::class)->events());
        $modelPrune = $events->first(fn ($event): bool => str_contains(
            $event->command ?? '',
            'model:prune',
        ));
        $failedJobPrune = $events->first(fn ($event): bool => str_contains(
            $event->command ?? '',
            'queue:prune-failed --hours=720',
        ));

        $this->assertNotNull($modelPrune);
        $this->assertSame('0 0 * * *', $modelPrune->expression);
        $this->assertNotNull($failedJobPrune);
        $this->assertSame('0 0 * * *', $failedJobPrune->expression);
    }
}
