<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'idx_auth_audit_logs_created_at');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->index('created_at', 'idx_api_usage_logs_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('auth_audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_auth_audit_logs_created_at');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->dropIndex('idx_api_usage_logs_created_at');
        });
    }
};
