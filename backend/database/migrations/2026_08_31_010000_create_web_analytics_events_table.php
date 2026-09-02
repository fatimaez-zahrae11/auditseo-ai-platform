<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_analytics_events', function (Blueprint $table) {
            $table->id();
            $table->char('visitor_id_hash', 64)->nullable();
            $table->char('session_id_hash', 64)->nullable();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('path', 512);
            $table->string('page_title', 200)->nullable();
            $table->string('referrer_host', 253)->nullable();
            $table->string('event_type', 50)->default('page_view');
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
            $table->index(['visitor_id_hash', 'created_at']);
            $table->index(['session_id_hash', 'created_at']);
            $table->index(['path', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_analytics_events');
    }
};
